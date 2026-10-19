<?php
declare(strict_types=1);
namespace App;

final class Application
{
    public function __construct(public readonly Database $db, public readonly array $config) {}

    public function now(): string { return date('Y-m-d H:i:s'); }

    public function canUseRecipient(array $patient, string $email, bool $directSearch = false): bool
    {
        if (!$patient['active']) return false;
        // Direct searches deliver to the address declared for this request.
        // This validates the address format without asserting ownership of it.
        if ($directSearch) return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        // Local simulations create private MIME files, never external clinical deliveries.
        if ($this->config['mode'] === 'demo' && $this->config['mail_transport'] === 'local') return true;
        return $patient['email_verified_at'] !== null && hash_equals($patient['email'], $email);
    }

    private function verificationRecipient(?array $patient, string $email): ?array
    {
        if (!$patient || !$this->canUseRecipient($patient, $email)) return null;
        $patient['email'] = $email;
        return $patient;
    }

    public function event(?int $requestId, string $action, string $result): void
    {
        $this->db->insert('events', ['request_id' => $requestId, 'action' => $action, 'result' => $result, 'created_at' => $this->now()]);
    }

    public function limit(string $key, int $maximum, int $seconds): void
    {
        $bucket = hash_hmac('sha256', $key, $this->config['app_key']);
        $allowed = $this->db->transaction(function () use ($bucket, $maximum, $seconds) {
            $now = time();
            $this->db->run('INSERT IGNORE INTO rate_limits (bucket,hits,expires_at) VALUES (?,0,?)', [$bucket, $now + $seconds]);
            $row = $this->db->one('SELECT * FROM rate_limits WHERE bucket=? FOR UPDATE', [$bucket]);
            if ((int) $row['expires_at'] <= $now) {
                $row['hits'] = 0;
                $this->db->run('UPDATE rate_limits SET hits=0,expires_at=? WHERE bucket=?', [$now + $seconds, $bucket]);
            }
            if ((int) $row['hits'] >= $maximum) return false;
            $this->db->run('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?', [$bucket]);
            return true;
        });
        if (!$allowed) throw new \DomainException('Has alcanzado el límite de intentos. Inténtalo más tarde.');
    }

    public function identity(array $data): array
    {
        $type = strtoupper(trim((string) ($data['document_type'] ?? '')));
        $number = strtoupper(trim((string) ($data['document_number'] ?? '')));
        $pattern = in_array($type, ['CC', 'TI', 'RC'], true) ? '/^[0-9]{3,20}$/' : '/^[A-Z0-9]{3,24}$/';
        if (!in_array($type, ['CC', 'CE', 'TI', 'RC', 'PA', 'PPT'], true) || !preg_match($pattern, $number)) {
            throw new \DomainException('Revisa el tipo y número de documento.');
        }
        return [$type, $number];
    }

    public function filters(array $data, bool $allowUnknownDate = false): array
    {
        $unknown = $allowUnknownDate && ($data['date_unknown'] ?? '') === '1';
        $date = $unknown ? null : (string) ($data['approximate_date'] ?? '');
        $parsed = $date === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$unknown && (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d'))) {
            throw new \DomainException('Escribe una fecha válida que no sea futura.');
        }
        $specialty = empty($data['specialty_id']) ? null : (int) $data['specialty_id'];
        if ($specialty !== null && !$this->db->one('SELECT id FROM specialties WHERE id=? AND active=1', [$specialty])) {
            throw new \DomainException('Selecciona una especialidad válida.');
        }
        return [$date, $specialty];
    }

    /** $whatsappPhone: número escrito en el formulario web; se usa directamente, sin confirmación desde el chat. */
    public function createRequest(array $data, string $ip, bool $directSearch = false, ?int $whatsappLinkId = null, ?string $whatsappPhone = null): int
    {
        [$type, $number] = $this->identity($data);
        [$date, $specialty] = $this->filters($data, $directSearch);
        $name = trim((string) ($data['full_name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) throw new \DomainException('Escribe el nombre completo del paciente.');
        $email = trim((string) ($data['email'] ?? ''));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \DomainException('Escribe un correo electrónico válido.');
        // Do not persist unverified contact updates in the patient record.
        $this->limit('request-ip:' . $ip, 20, 3600);
        $this->limit('request-patient:' . $type . ':' . $number, 5, 3600);
        $id = $this->db->transaction(function () use ($type, $number, $name, $date, $specialty, $email, $directSearch, $whatsappLinkId, $whatsappPhone) {
            $patient = $this->db->one('SELECT * FROM patients WHERE document_type=? AND document_number=? AND active=1', [$type, $number]);
            if ($directSearch && $patient && self::nameKey($patient['full_name']) !== self::nameKey($name)) $patient=null;
            $id = $this->db->insert('requests', [
                'reference' => 'HC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(5))),
                'patient_id' => $patient['id'] ?? null, 'claimed_name' => $name, 'contact_email' => $email,
                'claimed_document_type' => $type, 'claimed_document_number' => $number,
                'approximate_date' => $date, 'specialty_id' => $specialty, 'created_at' => $this->now(),
                'direct_search' => $directSearch ? 1 : 0,
                'whatsapp_phone' => $whatsappLinkId === null ? $whatsappPhone : null,
            ]);
            if (!$directSearch) $this->issueCode($id, $this->verificationRecipient($patient, $email));
            if ($whatsappLinkId !== null) $this->whatsapp()->attachLink($whatsappLinkId, $id);
            $this->saveProfile($type, $number, $name, $email, $this->request($id)['whatsapp_phone'], $patient !== null);
            $this->event($id, 'request_created', 'ok');
            return $id;
        });
        if ($directSearch) $this->processSearch($id);
        return $id;
    }

    /**
     * Guarda los datos declarados en la solicitud para que el bot no los vuelva a pedir. Un perfil con otro nombre
     * solo se reemplaza si el nombre nuevo coincide con el registro del paciente (evita que un tercero lo cambie).
     */
    private function saveProfile(string $type, string $number, string $name, string $email, ?string $phone, bool $patientMatched): void
    {
        $profile = $this->db->one('SELECT * FROM patient_profiles WHERE document_type=? AND document_number=? FOR UPDATE', [$type, $number]);
        if (!$profile) {
            $id = $this->db->insert('patient_profiles', ['document_type' => $type, 'document_number' => $number, 'full_name' => $name, 'email' => $email,
                'whatsapp_phone' => $phone, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
            if ($phone) $this->rememberProfileChat($id, $phone);
            return;
        }
        if (!$patientMatched && self::nameKey($profile['full_name']) !== self::nameKey($name)) return;
        $this->db->run('UPDATE patient_profiles SET full_name=?,email=?,whatsapp_phone=COALESCE(?,whatsapp_phone),updated_at=? WHERE id=?', [$name, $email, $phone, $this->now(), $profile['id']]);
        if ($phone) $this->rememberProfileChat((int)$profile['id'], $phone);
    }

    /** Recuerda que este WhatsApp ya demostró conocer al paciente del perfil: el bot no le vuelve a pedir el nombre. */
    public function rememberProfileChat(int $profileId, string $phone): void
    {
        $this->db->run('INSERT INTO patient_profile_chats (profile_id,phone,verified_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE verified_at=VALUES(verified_at)', [$profileId, $phone, $this->now()]);
    }

    /** ¿Este WhatsApp es el del perfil o ya fue validado para él? */
    public function isKnownProfileChat(array $profile, string $phone): bool
    {
        return $profile['whatsapp_phone'] === $phone || (bool)$this->db->one('SELECT 1 FROM patient_profile_chats WHERE profile_id=? AND phone=?', [$profile['id'], $phone]);
    }

    public function whatsapp(): WhatsApp { return new WhatsApp($this); }

    /** Estados en que una solicitud espera que la persona encargada la gestione. */
    public const PENDING_STATUSES = ['no_matches', 'unavailable', 'not_eligible'];

    /**
     * Atenciones con historia disponible del mismo documento que declaró el paciente.
     * name_matches indica si el nombre del PDF coincide con el nombre escrito en la solicitud.
     */
    public function candidateEncounters(int $requestId): array
    {
        $request = $this->request($requestId);
        if (!$request['claimed_document_type'] || !$request['claimed_document_number']) return [];
        $rows = $this->db->all("SELECT e.*,s.name specialty,p.full_name patient_name,k.id package_id,
                (SELECT COUNT(*) FROM clinical_files f WHERE f.package_id=k.id) file_count
            FROM patients p JOIN encounters e ON e.patient_id=p.id JOIN specialties s ON s.id=e.specialty_id
            JOIN packages k ON k.encounter_id=e.id AND k.status='available'
            WHERE p.document_type=? AND p.document_number=? AND p.active=1 ORDER BY e.attended_at DESC LIMIT 50",
            [$request['claimed_document_type'], $request['claimed_document_number']]);
        foreach ($rows as &$row) $row['name_matches'] = self::nameKey($row['patient_name']) === self::nameKey($request['claimed_name']);
        return $rows;
    }

    /**
     * La persona encargada elige la atención que corresponde a una solicitud pendiente.
     * Solo con el mismo documento y nombre; el envío reutiliza confirm() y sus validaciones.
     */
    public function assignEncounter(int $requestId, int $encounterId, int $adminId): void
    {
        $before = $this->db->transaction(function () use ($requestId, $encounterId, $adminId) {
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$requestId]);
            if (!$request || !$request['direct_search'] || !in_array($request['status'], self::PENDING_STATUSES, true)) throw new \DomainException('La solicitud ya fue gestionada o no admite esta acción. Actualiza la página.');
            $candidate = null;
            foreach ($this->candidateEncounters($requestId) as $row) if ((int)$row['id'] === $encounterId) $candidate = $row;
            if (!$candidate) throw new \DomainException('La atención no corresponde al documento de la solicitud o ya no está disponible.');
            if (!$candidate['name_matches']) throw new \DomainException('El nombre de la solicitud no coincide con el del documento. Responde «No registra» para que el paciente verifique sus datos.');
            $this->packageFiles((int)$candidate['package_id']);
            $this->db->run("UPDATE requests SET patient_id=?,matched_at=COALESCE(matched_at,?),approximate_date=?,specialty_id=NULL,status='matched',
                resolved_by=?,resolved_at=?,resolution='assigned' WHERE id=?",
                [$candidate['patient_id'], $this->now(), substr($candidate['attended_at'], 0, 10), $adminId, $this->now(), $requestId]);
            $this->event($requestId, 'request_assigned', 'admin_' . $adminId);
            return $request;
        });
        // confirm() vuelve a validar pertenencia, integridad y destinatario, y encola correo y WhatsApp.
        try { $this->confirm($requestId, $encounterId); }
        catch (\Throwable $error) { $this->restorePending($before); throw $error; }
        if ($this->request($requestId)['status'] !== 'queued') {
            $this->restorePending($before);
            throw new \DomainException('No se pudo preparar el envío de esa atención. Revisa que el paquete esté completo.');
        }
    }

    /** Si el envío no se pudo preparar, la solicitud vuelve a la cola de pendientes tal como estaba. */
    private function restorePending(array $before): void
    {
        $this->db->run('UPDATE requests SET patient_id=?,matched_at=?,approximate_date=?,specialty_id=?,encounter_id=?,status=?,resolved_by=NULL,resolved_at=NULL,resolution=NULL WHERE id=?',
            [$before['patient_id'], $before['matched_at'], $before['approximate_date'], $before['specialty_id'], $before['encounter_id'], $before['status'], $before['id']]);
        $this->event((int)$before['id'], 'request_assigned', 'reverted');
    }

    public const RESPONSE_MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * Respuesta de la persona encargada desde el detalle de la solicitud: estado (approved = Aprobado,
     * not_registered = No Registra), comentario para el paciente y archivos adjuntos (PDF, JPG o PNG).
     * Sale por correo y, si la solicitud tiene WhatsApp, también por ese chat. Aprobado exige al menos un archivo.
     * $sources: [['path' => ..., 'name' => ...]] (la capa HTTP solo pasa archivos subidos verificados).
     */
    public function respondCase(int $requestId, int $adminId, string $decision, string $comment, array $sources): void
    {
        if (!in_array($decision, ['approved', 'not_registered'], true)) throw new \DomainException('Selecciona el estado de la respuesta.');
        $comment = trim(str_replace("\r\n", "\n", $comment));
        if (mb_strlen($comment) < 10 || mb_strlen($comment) > 3000) throw new \DomainException('Escribe un comentario para el paciente (entre 10 y 3000 caracteres).');
        if (count($sources) > 10) throw new \DomainException('Adjunta como máximo 10 archivos.');
        if ($decision === 'approved' && !$sources) throw new \DomainException('Para aprobar adjunta los documentos que recibirá el paciente.');
        $files = []; $total = 0;
        foreach ($sources as $source) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source['path']) ?: '';
            if (!isset(self::RESPONSE_MIMES[$mime])) throw new \DomainException('Solo se pueden adjuntar archivos PDF, JPG o PNG.');
            $size = (int)filesize($source['path']);
            if ($size <= 0) throw new \DomainException('Uno de los archivos está vacío.');
            $total += $size;
            $name = trim(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename((string)$source['name']))) ?: 'documento';
            $files[] = ['path' => $source['path'], 'name' => mb_substr($name, 0, 150), 'mime' => $mime, 'size' => $size];
        }
        if ($total > (int)$this->config['max_response_bytes']) throw new \DomainException('Los adjuntos superan ' . intdiv((int)$this->config['max_response_bytes'], 1048576) . ' MB en total, el límite para enviarlos por correo.');
        $directory = $this->config['storage_path'] . '/responses';
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $stored = [];
        try {
            foreach ($files as &$file) {
                $file['storage'] = bin2hex(random_bytes(16)) . '.' . self::RESPONSE_MIMES[$file['mime']];
                if (!copy($file['path'], $directory . '/' . $file['storage'])) throw new \RuntimeException('No se pudo guardar un adjunto.');
                $stored[] = $directory . '/' . $file['storage'];
                $file['sha256'] = hash_file('sha256', $directory . '/' . $file['storage']);
            }
            unset($file);
            $this->db->transaction(function () use ($requestId, $adminId, $decision, $comment, $files) {
                $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$requestId]);
                if (!$request || !$request['direct_search'] || !in_array($request['status'], self::PENDING_STATUSES, true)) throw new \DomainException('La solicitud ya fue gestionada o no admite esta acción. Actualiza la página.');
                $sealed = Crypto::seal($comment, $this->config['app_key']);
                $this->db->run('UPDATE requests SET status=?,resolved_by=?,resolved_at=?,resolution=?,response_note=? WHERE id=?',
                    [$decision === 'approved' ? 'queued' : 'not_registered', $adminId, $this->now(), $decision, $sealed, $requestId]);
                $ids = [];
                foreach ($files as $index => $file) $ids[] = $this->db->insert('response_files', ['request_id' => $requestId, 'storage_name' => $file['storage'], 'original_name' => $file['name'],
                    'mime' => $file['mime'], 'size_bytes' => $file['size'], 'sha256' => $file['sha256'], 'sort_order' => $index, 'created_at' => $this->now()]);
                $in = $ids ? implode(',', array_map('intval', $ids)) : '0';
                $unique = bin2hex(random_bytes(4));
                if ($request['contact_email'] && filter_var($request['contact_email'], FILTER_VALIDATE_EMAIL)) {
                    $jobId = $this->queue($requestId, $decision === 'approved' ? 'response' : 'notice', $request['contact_email'], null, null, $sealed, 'response:' . $requestId . ':' . $unique);
                    $this->db->run("UPDATE response_files SET job_id=? WHERE id IN ($in)", [$jobId]);
                }
                $waJob = $this->whatsapp()->queueResponse($requestId, $decision, $comment, $unique);
                if ($waJob) $this->db->run("UPDATE response_files SET wa_job_id=? WHERE id IN ($in)", [$waJob]);
                $this->event($requestId, 'request_responded', $decision . ':admin_' . $adminId . ':files_' . count($files));
            });
        } catch (\Throwable $error) {
            foreach ($stored as $path) @unlink($path);
            throw $error;
        }
    }

    /** Adjuntos de una respuesta, verificados (existen y no cambiaron). $column: job_id (correo) o wa_job_id. */
    public function responseFiles(int $jobId, string $column = 'job_id'): array
    {
        if (!in_array($column, ['job_id', 'wa_job_id'], true)) throw new \InvalidArgumentException('column');
        $files = $this->db->all("SELECT * FROM response_files WHERE $column=? ORDER BY sort_order,id", [$jobId]);
        foreach ($files as &$file) {
            $file['path'] = $this->config['storage_path'] . '/responses/' . $file['storage_name'];
            if (!is_file($file['path']) || filesize($file['path']) !== (int)$file['size_bytes'] || !hash_equals($file['sha256'], hash_file('sha256', $file['path']))) throw new \DomainException('response_file_unavailable');
        }
        return $files;
    }

    /** Respuesta «No registra»: cierra la solicitud y avisa al paciente por WhatsApp (si aplica) y correo. */
    public function markNotRegistered(int $requestId, int $adminId): void
    {
        $this->db->transaction(function () use ($requestId, $adminId) {
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$requestId]);
            if (!$request || !$request['direct_search'] || !in_array($request['status'], self::PENDING_STATUSES, true)) throw new \DomainException('La solicitud ya fue gestionada o no admite esta acción. Actualiza la página.');
            $this->db->run("UPDATE requests SET status='not_registered',resolved_by=?,resolved_at=?,resolution='not_registered' WHERE id=?", [$adminId, $this->now(), $requestId]);
            $body = (new MessageTemplates($this))->renderPlain('not_registered', ['radicado' => $request['reference'], 'nombre' => $request['claimed_name'], 'enlace' => $this->whatsapp()->portalUrl()]);
            if ($request['contact_email'] && filter_var($request['contact_email'], FILTER_VALIDATE_EMAIL)) {
                $this->queue($requestId, 'notice', $request['contact_email'], null, null, Crypto::seal($body, $this->config['app_key']), 'notice:not_registered:' . $requestId);
            }
            $this->whatsapp()->queueNotRegistered($requestId);
            $this->event($requestId, 'request_not_registered', 'admin_' . $adminId);
        });
    }

    /**
     * Cuando se carga una historia, reprocesa las solicitudes de WhatsApp que quedaron sin historia
     * (radicado ya informado) para ese mismo paciente. Si ahora hay coincidencia se envía sola.
     */
    /** Reprocesa las solicitudes de WhatsApp que esperaban este PDF. Devuelve [radicado, enviada] de cada una. */
    public function resolveWaitingRequests(int $packageId): array
    {
        $patient = $this->db->one('SELECT p.* FROM packages k JOIN encounters e ON e.id=k.encounter_id JOIN patients p ON p.id=e.patient_id WHERE k.id=?', [$packageId]);
        if (!$patient) return [];
        $resolved = [];
        $since = date('Y-m-d H:i:s', time() - 86400 * (int)$this->config['whatsapp_pending_days']);
        $waiting = $this->db->all("SELECT id,claimed_name,patient_id FROM requests WHERE direct_search=1 AND whatsapp_phone IS NOT NULL
            AND status IN ('no_matches','unavailable') AND claimed_document_type=? AND claimed_document_number=? AND created_at>=? ORDER BY id",
            [$patient['document_type'], $patient['document_number'], $since]);
        foreach ($waiting as $request) {
            if (self::nameKey($patient['full_name']) !== self::nameKey($request['claimed_name'])) continue;
            // La solicitud pudo crearse antes de que el paciente existiera en el repositorio.
            if ($request['patient_id'] === null) $this->db->run('UPDATE requests SET patient_id=? WHERE id=? AND patient_id IS NULL', [$patient['id'], $request['id']]);
            $this->processSearch((int)$request['id']);
            $after = $this->request((int)$request['id']);
            $this->event((int)$request['id'], 'whatsapp_pending_resolved', $after['status']);
            $resolved[] = ['reference' => $after['reference'], 'sent' => $after['status'] === 'queued'];
        }
        return $resolved;
    }

    public static function nameKey(string $name): string
    {
        return mb_strtoupper(ClinicalPdfReader::normalize(trim(preg_replace('/\s+/u',' ',$name))),'UTF-8');
    }

    /**
     * Búsqueda directa en los PDF. $final: el paciente ya indicó su fecha aproximada, así que si no hay historia
     * se le informa el radicado. Si no es final y la página le sugiere una consulta, el aviso de «no encontrada»
     * espera: puede que la elija (releaseDeferredNotices lo envía si no decide).
     */
    public function processSearch(int $id, bool $final = false): void
    {
        $request=$this->request($id);
        if (!$request['direct_search'] || !in_array($request['status'],['pending_verification','matched','no_matches','unavailable','awaiting_selection'],true)) return;
        $patient=$this->db->one('SELECT * FROM patients WHERE id=? AND active=1',[$request['patient_id']]);
        if (!$patient || self::nameKey($patient['full_name'])!==self::nameKey($request['claimed_name'])) {
            $this->db->run("UPDATE requests SET status='no_matches' WHERE id=?",[$id]); $this->whatsapp()->queueNotFound($id);
            $this->event($id,'pdf_search','patient_not_found'); return;
        }
        if (!$this->canUseRecipient($patient,$request['contact_email'],true)) {
            $this->db->run("UPDATE requests SET status='not_eligible' WHERE id=?",[$id]); $this->whatsapp()->queueNotFound($id);
            $this->event($id,'pdf_search','not_eligible'); return;
        }
        // Matching data is not identity/email verification. Keep verified_at/email empty.
        $this->db->run("UPDATE requests SET status='matched',matched_at=? WHERE id=?",[$this->now(),$id]);
        $matches=$this->search($id);
        if (!$matches) {
            $this->db->run("UPDATE requests SET status='no_matches' WHERE id=?",[$id]);
            if ($final || !$this->recentSuggestion($id)) $this->whatsapp()->queueNotFound($id);
            else $this->event($id,'notice_deferred','suggestion_shown');
        }
        elseif(count($matches)===1) $this->confirm($id,(int)$matches[0]['id']);
        else $this->db->run("UPDATE requests SET status='awaiting_selection' WHERE id=?",[$id]);
        $this->event($id,'pdf_search',count($matches)===1?'single_match':(count($matches)>1?'multiple_matches':'no_matches'));
    }

    private function issueCode(int $requestId, ?array $patient): void
    {
        $code = (string) random_int(100000, 999999);
        $this->db->run('UPDATE verifications SET consumed_at=? WHERE request_id=? AND consumed_at IS NULL', [$this->now(), $requestId]);
        $this->db->run("UPDATE jobs SET status='cancelled',encrypted_payload=NULL WHERE request_id=? AND kind='otp' AND status IN ('queued','retry')", [$requestId]);
        $verificationId = $this->db->insert('verifications', [
            'request_id' => $requestId, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'destination' => $patient['email'] ?? '', 'expires_at' => date('Y-m-d H:i:s', time() + $this->config['otp_seconds']),
            'created_at' => $this->now(),
        ]);
        if ($patient) {
            $this->queue($requestId, 'otp', $patient['email'], null, $verificationId, Crypto::seal($code, $this->config['app_key']));
        }
    }

    public function resend(int $requestId, string $ip): void
    {
        $this->limit('resend-ip:' . $ip, 15, 3600);
        $this->db->transaction(function () use ($requestId) {
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$requestId]);
            if (!$request || !in_array($request['status'], ['pending_verification','expired'], true)) throw new \DomainException('Inicia una nueva solicitud.');
            $last = $this->db->one('SELECT * FROM verifications WHERE request_id=? ORDER BY id DESC LIMIT 1', [$requestId]);
            if ($last && strtotime($last['created_at']) + $this->config['resend_seconds'] > time()) throw new \DomainException('Espera 60 segundos antes de solicitar otro código.');
            $count = $this->db->one('SELECT COUNT(*) total FROM verifications WHERE request_id=?', [$requestId]);
            if ((int) $count['total'] >= 5) throw new \DomainException('Se agotaron los reenvíos de esta solicitud. Inicia otra más tarde.');
            $patient = $this->db->one('SELECT * FROM patients WHERE id=? AND active=1', [$request['patient_id']]);
            $this->issueCode($requestId, $this->verificationRecipient($patient, $request['contact_email'] ?? ''));
            $this->db->run("UPDATE requests SET status='pending_verification',verified_email=NULL,verified_at=NULL WHERE id=?", [$requestId]);
            $this->event($requestId, 'code_renewed', 'ok');
        });
    }

    public function verify(int $requestId, string $code, string $ip): bool
    {
        $this->limit('verify-ip:' . $ip, 30, 3600);
        return $this->db->transaction(function () use ($requestId, $code) {
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$requestId]);
            $verification = $this->db->one('SELECT * FROM verifications WHERE request_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$requestId]);
            if (!$request || !$verification || $request['status'] !== 'pending_verification' || $verification['consumed_at']) return false;
            if (strtotime($verification['expires_at']) <= time() || (int) $verification['attempts'] >= $this->config['otp_attempts']) {
                $this->db->run("UPDATE requests SET status='expired' WHERE id=?", [$requestId]);
                return false;
            }
            $this->db->run('UPDATE verifications SET attempts=attempts+1 WHERE id=?', [$verification['id']]);
            $matches = password_verify($code, $verification['code_hash']);
            $patient = $this->db->one('SELECT * FROM patients WHERE id=? AND active=1', [$request['patient_id']]);
            if (!$matches || !$patient || !$this->canUseRecipient($patient, $verification['destination']) || !hash_equals($verification['destination'], $request['contact_email'] ?? '')) {
                $this->event($requestId, 'verification', 'invalid');
                return false;
            }
            $this->db->run('UPDATE verifications SET consumed_at=? WHERE id=?', [$this->now(), $verification['id']]);
            $this->db->run("UPDATE requests SET status='verified',verified_at=?,verified_email=? WHERE id=?", [$this->now(), $verification['destination'], $requestId]);
            $this->event($requestId, 'verification', 'ok');
            return true;
        });
    }

    public function request(int $id): array
    {
        return $this->db->one('SELECT * FROM requests WHERE id=?', [$id]) ?? throw new \DomainException('Solicitud no disponible.');
    }

    public function requireVerified(int $id): array
    {
        $request = $this->request($id);
        if ($request['direct_search']) {
            $patient=$this->db->one('SELECT * FROM patients WHERE id=? AND active=1',[$request['patient_id']]);
            if (!$request['matched_at'] || $request['status']==='expired' || !$patient || !$this->canUseRecipient($patient,$request['contact_email'],true) || self::nameKey($patient['full_name'])!==self::nameKey($request['claimed_name'])) throw new \DomainException('No se puede continuar con los datos de esta solicitud.');
            return $request;
        }
        if (!$request['verified_at'] || in_array($request['status'], ['pending_verification','expired'], true)) throw new \DomainException('Verifica nuevamente tu solicitud.');
        $patient = $this->db->one('SELECT * FROM patients WHERE id=? AND active=1', [$request['patient_id']]);
        if (!$patient || !$this->canUseRecipient($patient, $request['verified_email'] ?? '') || !hash_equals($request['verified_email'] ?? '', $request['contact_email'] ?? '')) throw new \DomainException('El registro ha cambiado o el destino no está habilitado. Inicia una nueva solicitud para verificarlo.');
        return $request;
    }

    public function search(int $id, bool $previousDelivery = false): array
    {
        $request = $this->requireVerified($id);
        if ($request['approximate_date'] === null) return [];
        $date = new \DateTimeImmutable($request['approximate_date']);
        // New requests try the selected day first; alternatives need patient selection.
        $days = $request['direct_search'] && !$previousDelivery ? 0 : (int) $this->config['search_days'];
        $start = $date->modify("-$days days")->format('Y-m-d') . ' 00:00:00';
        $end = min($date->modify("+$days days")->format('Y-m-d'), date('Y-m-d')) . ' 23:59:59';
        $sql = 'SELECT e.*,s.name specialty FROM encounters e JOIN specialties s ON s.id=e.specialty_id WHERE e.patient_id=? AND e.attended_at BETWEEN ? AND ?';
        $params = [$request['patient_id'], $start, $end];
        if ($request['specialty_id']) { $sql .= ' AND e.specialty_id=?'; $params[] = $request['specialty_id']; }
        return $this->db->all($sql . ' ORDER BY e.attended_at DESC', $params);
    }

    /** $final: fecha aproximada indicada por el paciente después de la sugerencia (si no hay historia, recibe el radicado). */
    public function changeFilters(int $id, array $data, bool $final = false): void
    {
        $request = $this->requireVerified($id);
        if (!in_array($request['status'], ['verified','matched','awaiting_selection','no_matches','unavailable'], true)) throw new \DomainException('Esta solicitud ya fue procesada.');
        [$date, $specialty] = $this->filters($data, (bool)$request['direct_search']);
        $this->db->run('UPDATE requests SET approximate_date=?,specialty_id=?,status=? WHERE id=?', [$date, $specialty, $request['direct_search'] ? 'matched' : 'verified', $id]);
        if ($request['direct_search']) $this->processSearch($id, $final);
    }

    /**
     * Envía el radicado por WhatsApp a las solicitudes pendientes cuyo aviso quedó en espera porque se le sugirió una
     * consulta al paciente y no decidió en $minutes minutos. Lo llama el procesador; devuelve cuántos avisos encoló.
     */
    public function releaseDeferredNotices(int $minutes = 20): int
    {
        $rows = $this->db->all("SELECT r.id FROM requests r WHERE r.direct_search=1 AND r.whatsapp_phone IS NOT NULL AND r.created_at<?
            AND r.status IN ('" . implode("','", self::PENDING_STATUSES) . "')
            AND NOT EXISTS (SELECT 1 FROM whatsapp_outbox o WHERE o.request_id=r.id AND o.kind IN ('notice','history','response','not_registered'))
            AND EXISTS (SELECT 1 FROM events e WHERE e.request_id=r.id AND e.action='notice_deferred')", [date('Y-m-d H:i:s', time() - 60 * $minutes)]);
        foreach ($rows as $row) $this->whatsapp()->queueNotFound((int)$row['id']);
        return count($rows);
    }

    /**
     * Atenciones del paciente con historia completa y disponible (las que se pueden entregar), de la más reciente a la
     * más antigua. Solo para búsquedas directas sin coincidencia y con el nombre ya validado (si no, lista vacía).
     */
    public function availableEncounters(int $id, ?string $from = null, ?string $to = null, ?int $specialty = null, int $limit = 1): array
    {
        $request = $this->request($id);
        if (!$request['direct_search'] || !in_array($request['status'], ['no_matches','unavailable'], true)) return [];
        try { $request = $this->requireVerified($id); }
        catch (\DomainException) { return []; }
        $sql = "SELECT e.*,s.name specialty,p.id package_id FROM encounters e
            JOIN specialties s ON s.id=e.specialty_id
            JOIN packages p ON p.encounter_id=e.id AND p.status='available'
            WHERE e.patient_id=? AND e.attended_at<=?
            AND NOT EXISTS (SELECT 1 FROM packages other WHERE other.encounter_id=e.id AND other.status='available' AND other.id<>p.id)
            AND EXISTS (SELECT 1 FROM clinical_files f WHERE f.package_id=p.id)";
        $params = [$request['patient_id'], $this->now()];
        if ($from !== null) { $sql .= ' AND e.attended_at>=?'; $params[] = $from . ' 00:00:00'; }
        if ($to !== null) { $sql .= ' AND e.attended_at<=?'; $params[] = $to . ' 23:59:59'; }
        if ($specialty) { $sql .= ' AND e.specialty_id=?'; $params[] = $specialty; }
        $found = [];
        foreach ($this->db->all($sql . ' ORDER BY e.attended_at DESC,e.id DESC', $params) as $encounter) {
            try { $this->packageFiles((int)$encounter['package_id']); }
            catch (\DomainException) { continue; }
            $found[] = $encounter;
            if (count($found) >= $limit) break;
        }
        return $found;
    }

    public function recentSuggestion(int $id): ?array
    {
        $request = $this->request($id);
        if (!$request['direct_search']) return null;
        // La sugerencia excluye el día que el paciente ya indicó (no tuvo coincidencia) y respeta su especialidad.
        foreach ($this->availableEncounters($id, null, null, $request['specialty_id'] ? (int)$request['specialty_id'] : null, 50) as $encounter) {
            if ($request['approximate_date'] === null || substr($encounter['attended_at'], 0, 10) !== $request['approximate_date']) return $encounter;
        }
        return null;
    }

    public function selectSuggestion(int $id, int $encounterId): void
    {
        $this->db->transaction(function () use ($id, $encounterId) {
            $this->db->one('SELECT id FROM requests WHERE id=? FOR UPDATE', [$id]);
            $suggestion = $this->recentSuggestion($id);
            if (!$suggestion || (int)$suggestion['id'] !== $encounterId) throw new \DomainException('La sugerencia cambió o ya no está disponible. Actualiza la página.');
            $this->db->run("UPDATE requests SET approximate_date=?,status='matched' WHERE id=?", [substr($suggestion['attended_at'],0,10), $id]);
            $this->event($id, 'suggestion_selected', 'date_updated');
        });
        // Confirm rechecks ownership and integrity and queues only the selected encounter.
        $this->confirm($id, $encounterId);
    }

    public function confirm(int $id, int $encounterId): void
    {
        $this->db->transaction(function () use ($id, $encounterId) {
            $this->db->one('SELECT id FROM requests WHERE id=? FOR UPDATE', [$id]);
            $request = $this->requireVerified($id);
            if (!in_array($request['status'], ['verified','matched','awaiting_selection','no_matches','unavailable'], true)) return;
            $matches = array_column($this->search($id), 'id');
            if (!in_array($encounterId, array_map('intval', $matches), true)) throw new \DomainException('La atención no corresponde a esta solicitud.');
            $packages = $this->db->all("SELECT * FROM packages WHERE encounter_id=? AND status='available' FOR UPDATE", [$encounterId]);
            if (count($packages) !== 1) {
                $this->db->run("UPDATE requests SET status='unavailable',encounter_id=? WHERE id=?", [$encounterId, $id]);
                $this->whatsapp()->queueNotFound($id);
                return;
            }
            try { $this->packageFiles((int) $packages[0]['id']); }
            catch (\DomainException) {
                $this->db->run("UPDATE requests SET status='unavailable',encounter_id=? WHERE id=?", [$encounterId, $id]);
                $this->event($id, 'package_validation', 'unavailable');
                $this->whatsapp()->queueNotFound($id);
                return;
            }
            $this->queue($id, 'history', $request['direct_search'] ? $request['contact_email'] : $request['verified_email'], (int) $packages[0]['id']);
            $this->db->run("UPDATE requests SET status='queued',encounter_id=? WHERE id=?", [$encounterId, $id]);
            // Si la solicitud llegó por WhatsApp, la misma historia se envía también al chat.
            $this->whatsapp()->queueHistory($id, (int) $packages[0]['id']);
            $this->event($id, 'delivery_requested', 'queued');
        });
    }

    public function historyResendInfo(int $id): array
    {
        $jobs = $this->db->all("SELECT * FROM jobs WHERE request_id=? AND kind='history' ORDER BY id DESC", [$id]);
        $last = $jobs[0] ?? null;
        $remaining = max(0, (int)$this->config['history_resend_max'] - max(0, count($jobs) - 1));
        $wait = $last ? max(0, strtotime($last['sent_at'] ?? $last['locked_at'] ?? $last['created_at']) + (int)$this->config['history_resend_seconds'] - time()) : 0;
        $active = array_filter($jobs, fn(array $job) => in_array($job['status'], ['queued','retry','sending'], true));
        return ['job' => $last, 'remaining' => $remaining, 'wait' => $wait,
            'eligible' => $last && !$active && in_array($last['status'], ['sent','simulated','failed','uncertain'], true)];
    }

    public function resendHistory(int $id, int $expectedJobId, string $ip): void
    {
        $this->limit('history-resend-ip:' . $ip, 15, 3600);
        $this->db->transaction(function () use ($id, $expectedJobId) {
            $this->db->one('SELECT id FROM requests WHERE id=? FOR UPDATE', [$id]);
            $request = $this->requireVerified($id);
            $info = $this->historyResendInfo($id);
            $last = $info['job'];
            if (!$info['eligible'] || !$last || (int)$last['id'] !== $expectedJobId || !in_array($request['status'], ['sent','simulated','failed','uncertain'], true)) {
                throw new \DomainException('El envío ya está en proceso o cambió de estado. Actualiza la página.');
            }
            if (!$info['remaining']) throw new \DomainException('Alcanzaste el máximo de reenvíos de esta solicitud.');
            if ($info['wait']) throw new \DomainException('Espera ' . $info['wait'] . ' segundos antes de reenviar la historia.');
            if (!hash_equals($request['contact_email'] ?? '', $last['recipient'])) throw new \DomainException('El correo de esta solicitud cambió. Inicia una nueva solicitud.');
            $package = $this->db->one('SELECT p.*,e.patient_id FROM packages p JOIN encounters e ON e.id=p.encounter_id WHERE p.id=? FOR UPDATE', [$last['package_id']]);
            if (!$package || (int)$package['patient_id'] !== (int)$request['patient_id'] || (int)$package['encounter_id'] !== (int)$request['encounter_id'] || !in_array((int)$request['encounter_id'], array_map('intval', array_column($this->search($id, true), 'id')), true)) {
                throw new \DomainException('La historia no corresponde a esta solicitud.');
            }
            $this->packageFiles((int)$package['id']);
            $this->queue($id, 'history', $last['recipient'], (int)$package['id'], null, null, 'history:' . $id . ':resend:' . $last['id']);
            $this->db->run("UPDATE requests SET status='queued' WHERE id=?", [$id]);
            $this->event($id, 'history_resent', 'queued');
        });
    }

    public function packageFiles(int $packageId): array
    {
        $package = $this->db->one("SELECT * FROM packages WHERE id=? AND status='available'", [$packageId]);
        $files = $this->db->all('SELECT * FROM clinical_files WHERE package_id=? ORDER BY sort_order,id', [$packageId]);
        if (!$package || !$files) throw new \DomainException('Paquete no disponible.');
        $estimated = 8192;
        foreach ($files as &$file) {
            if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $file['storage_name'])) throw new \DomainException('Archivo no disponible.');
            $file['path'] = $this->config['storage_path'] . '/histories/' . $file['storage_name'];
            if (!is_file($file['path']) || filesize($file['path']) !== (int) $file['size_bytes'] || !hash_equals($file['sha256'], hash_file('sha256', $file['path']))) throw new \DomainException('Archivo no disponible.');
            $estimated += (int) ceil((int) $file['size_bytes'] / 3) * 4 * 1.04 + 2048;
        }
        if ($estimated > $this->config['max_mail_bytes']) throw new \DomainException('El paquete supera el límite del correo.');
        return $files;
    }

    private function queue(int $requestId, string $kind, string $recipient, ?int $packageId = null, ?int $verificationId = null, ?string $payload = null, ?string $dedupeKey = null): int
    {
        return $this->db->insert('jobs', [
            'request_id' => $requestId, 'verification_id' => $verificationId, 'package_id' => $packageId,
            'kind' => $kind, 'recipient' => $recipient, 'encrypted_payload' => $payload,
            'available_at' => $this->now(), 'created_at' => $this->now(),
            'message_id' => '<' . bin2hex(random_bytes(18)) . '@citaya.local>',
            'dedupe_key' => $dedupeKey ?? ($kind === 'history' ? 'history:' . $requestId : 'otp:' . $verificationId),
        ]);
    }
}
