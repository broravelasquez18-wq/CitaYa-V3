<?php
declare(strict_types=1);
namespace App;

/**
 * Bot de WhatsApp (Cloud API de Meta): recibe mensajes, entrega el enlace del formulario
 * y envía la historia o el radicado cuando la solicitud se resuelve.
 * Los mensajes salientes pasan por whatsapp_outbox y los procesa bin/worker.php.
 */
final class WhatsApp
{
    private const KEYWORDS = ['historia', 'clinica', 'hc', 'epicrisis', 'copia', 'resultado', '1'];

    public function __construct(private readonly Application $app) {}

    public static function validSignature(string $body, string $header, string $secret): bool
    {
        return $secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }

    public static function maskPhone(string $phone): string
    {
        return '••• ' . substr($phone, -4);
    }

    /** Firma de Zernio: HMAC-SHA256 del cuerpo en hexadecimal, sin prefijo (cabecera X-Zernio-Signature). */
    public static function validZernioSignature(string $body, string $header, string $secret): bool
    {
        return $secret !== '' && hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($header)));
    }

    /** Procesa el JSON del webhook de Meta. Devuelve la cantidad de mensajes nuevos atendidos. */
    public function receive(array $payload): int
    {
        $handled = 0;
        foreach ($payload['entry'] ?? [] as $entry) foreach ($entry['changes'] ?? [] as $change) {
            foreach ($change['value']['messages'] ?? [] as $message) {
                $type = (string)($message['type'] ?? '');
                $text = $type === 'text' ? (string)($message['text']['body'] ?? '') : '';
                if ($type === 'interactive') $text = (string)($message['interactive']['button_reply']['id'] ?? '');
                $handled += (int)$this->handleInbound((string)($message['id'] ?? ''), (string)($message['from'] ?? ''), $text);
            }
        }
        return $handled;
    }

    /**
     * Procesa un evento del webhook de Zernio. Solo atiende message.received entrante de WhatsApp;
     * guarda la conversación del número porque las respuestas se envían a ella.
     */
    public function receiveZernio(array $payload): int
    {
        $message = $payload['message'] ?? null;
        if (($payload['event'] ?? '') !== 'message.received' || !is_array($message)) return 0;
        if (($message['platform'] ?? '') !== 'whatsapp' || ($message['direction'] ?? '') !== 'incoming') return 0;
        // Otros números conectados a la misma cuenta de Zernio no son el canal de CitaYa.
        $account = (string)($payload['account']['accountId'] ?? '');
        if ($account !== '' && $account !== (string)$this->app->config['zernio_account_id']) return 0;
        // sender.id es el teléfono sin «+» o, si el usuario lo oculta, su BSUID (no sirve como teléfono).
        $sender = $message['sender'] ?? [];
        $phone = (string)($sender['phoneNumber'] ?? '');
        if ($phone === '' && ctype_digit((string)($sender['id'] ?? ''))) $phone = (string)$sender['id'];
        $phone = preg_replace('/\D/', '', $phone);
        $conversation = (string)($payload['conversation']['id'] ?? $message['conversationId'] ?? '');
        if ($conversation === '' || strlen($conversation) > 190 || strlen($phone) < 8 || strlen($phone) > 20) return 0;
        $this->app->db->run('INSERT INTO whatsapp_conversations (phone,conversation_id,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE conversation_id=VALUES(conversation_id),updated_at=VALUES(updated_at)', [$phone, $conversation, $this->app->now()]);
        $text = (string)($payload['metadata']['interactiveId'] ?? '') ?: (string)($message['text'] ?? '');
        return (int)$this->handleInbound((string)($message['platformMessageId'] ?? $message['id'] ?? ''), $phone, $text);
    }

    /** Número escrito en el formulario web: solo dígitos; un celular colombiano de 10 dígitos recibe el indicativo 57. */
    public static function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 10 && $digits[0] === '3') $digits = '57' . $digits;
        if (strlen($digits) < 10 || strlen($digits) > 15) throw new \DomainException('Escribe un número de WhatsApp válido, con indicativo si no es de Colombia.');
        return $digits;
    }

    /** Número de un enlace del formulario (para mostrarlo al paciente). */
    public function linkPhone(int $linkId): ?string
    {
        return $this->app->db->one('SELECT phone FROM whatsapp_links WHERE id=?', [$linkId])['phone'] ?? null;
    }

    /**
     * El paciente escribió su WhatsApp en el formulario web. No se le envía nada hasta que lo confirme
     * escribiendo al bot «CONFIRMAR código» desde ese WhatsApp. Devuelve el código (solo se guarda su hash).
     */
    public function claimNumber(int $requestId, string $phone): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $this->app->db->run('UPDATE requests SET whatsapp_claimed=?,whatsapp_code_hash=?,whatsapp_code_expires=? WHERE id=? AND whatsapp_phone IS NULL', [
            $phone, hash('sha256', $code), date('Y-m-d H:i:s', time() + (int)$this->app->config['whatsapp_link_seconds']), $requestId,
        ]);
        $this->app->event($requestId, 'whatsapp_claimed', 'pending_confirmation');
        return $code;
    }

    /**
     * Valida «CONFIRMAR código» enviado desde un chat: vincula la solicitud a ese chat y encola lo que corresponda
     * (historia o radicado). Devuelve null si el código no es válido; si lo es, el radicado y el siguiente paso:
     * history (se encoló la historia), notice (se encoló el radicado), select (debe elegir atención en la página) o none.
     */
    public function confirmCode(string $phone, string $code): ?array
    {
        $request = $this->app->db->transaction(function () use ($phone, $code) {
            $request = $this->app->db->one('SELECT * FROM requests WHERE whatsapp_code_hash=? AND whatsapp_code_expires>? AND whatsapp_phone IS NULL FOR UPDATE', [hash('sha256', strtoupper($code)), $this->app->now()]);
            if (!$request) return null;
            $this->app->db->run('UPDATE requests SET whatsapp_phone=?,whatsapp_code_hash=NULL,whatsapp_code_expires=NULL WHERE id=?', [$phone, $request['id']]);
            $this->app->event((int)$request['id'], 'whatsapp_claimed', 'confirmed');
            return $this->app->request((int)$request['id']);
        });
        if (!$request) return null;
        $next = 'none';
        $delivered = $this->app->db->one("SELECT package_id FROM jobs WHERE request_id=? AND kind='history' ORDER BY id DESC LIMIT 1", [$request['id']]);
        if ($delivered && $delivered['package_id']) { $this->queueHistory((int)$request['id'], (int)$delivered['package_id']); $next = 'history'; }
        elseif (in_array($request['status'], Application::PENDING_STATUSES, true)) { $this->queueNotFound((int)$request['id']); $next = 'notice'; }
        elseif ($request['status'] === 'awaiting_selection') $next = 'select';
        return ['reference' => $request['reference'], 'next' => $next];
    }

    /** Respuesta del bot propio de CitaYa (sin n8n) a «CONFIRMAR código». */
    private function confirmNumber(string $phone, string $code, string $messageId): void
    {
        $result = $this->confirmCode($phone, $code);
        if (!$result) {
            $this->queueText($phone, 'El código no es válido o ya venció. Revisa el código de la página de tu solicitud, o escribe *HISTORIA* para iniciar una nueva.', 'confirm-fail:' . $messageId);
            return;
        }
        $this->queueText($phone, 'Listo, confirmamos este WhatsApp para tu solicitud con radicado *' . $result['reference'] . '*.', 'confirm-ok:' . $messageId);
        if ($result['next'] === 'select') $this->queueText($phone, 'Encontramos varias atenciones con tus datos. Elige la que necesitas en la página de tu solicitud y te la enviaremos por aquí.', 'confirm-select:' . $messageId);
    }

    private function handleInbound(string $id, string $from, string $text): bool
    {
        $phone = preg_replace('/\D/', '', $from);
        if ($id === '' || strlen($id) > 190 || strlen($phone) < 8 || strlen($phone) > 20) return false;
        if (preg_match('/\bconfirmar\s+([a-z0-9]{6})\b/i', $text, $match)) {
            $new = $this->app->db->run('INSERT IGNORE INTO whatsapp_inbound (wa_message_id,phone,intent,created_at) VALUES (?,?,?,?)', [$id, $phone, 'confirm', $this->app->now()])->rowCount();
            if (!$new) return false;
            try { $this->app->limit('whatsapp-confirm:' . $phone, 10, 3600); }
            catch (\DomainException) { return false; }
            $this->confirmNumber($phone, $match[1], $id);
            return true;
        }
        $intent = $this->intent($text);
        // Meta y Zernio reintentan el webhook: el id del mensaje evita respuestas duplicadas.
        $new = $this->app->db->run('INSERT IGNORE INTO whatsapp_inbound (wa_message_id,phone,intent,created_at) VALUES (?,?,?,?)', [$id, $phone, $intent, $this->app->now()])->rowCount();
        if (!$new) return false;
        try { $this->app->limit('whatsapp-phone:' . $phone, 20, 3600); }
        catch (\DomainException) { return false; }
        // Si había mensajes esperando porque la ventana de 24 h estaba cerrada, ya pueden salir.
        $released = $this->app->db->run("UPDATE whatsapp_outbox SET status='queued',last_error=NULL,available_at=? WHERE phone=? AND status='waiting_window'", [$this->app->now(), $phone])->rowCount();
        if ($released) $this->queueText($phone, 'Gracias por escribirnos. Te estamos enviando ahora tu historia clínica.', 'release:' . $id);
        if ($intent === 'history') $this->sendFormLink($phone);
        elseif (!$released) $this->queueText($phone, $this->menu(), 'menu:' . $id);
        return true;
    }

    private function intent(string $text): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(ClinicalPdfReader::normalize($text)), -1, PREG_SPLIT_NO_EMPTY);
        return array_intersect($words, self::KEYWORDS) ? 'history' : 'menu';
    }

    private function menu(): string
    {
        return $this->templates()->render('menu');
    }

    private function templates(): MessageTemplates { return new MessageTemplates($this->app); }

    /** Enlace público al formulario (sin token: para nuevas solicitudes desde un aviso). */
    public function portalUrl(): string
    {
        return rtrim($this->app->config['public_url'], '/') . '/?page=home';
    }

    /** Crea un enlace personal y de un solo uso al formulario, vinculado al chat. El token solo se guarda como hash. */
    public function createFormLink(string $phone): array
    {
        $token = bin2hex(random_bytes(24));
        $this->app->db->insert('whatsapp_links', [
            'token_hash' => hash('sha256', $token), 'phone' => $phone, 'created_at' => $this->app->now(),
            'expires_at' => date('Y-m-d H:i:s', time() + (int)$this->app->config['whatsapp_link_seconds']),
        ]);
        return ['url' => rtrim($this->app->config['public_url'], '/') . '/?page=home&wa=' . $token,
            'hours' => max(1, intdiv((int)$this->app->config['whatsapp_link_seconds'], 3600)), 'key' => hash('sha256', $token)];
    }

    public function sendFormLink(string $phone): void
    {
        $link = $this->createFormLink($phone);
        $this->queueText($phone, $this->templates()->render('form_link', ['enlace' => $link['url'], 'vigencia' => $link['hours']]), 'link:' . $link['key']);
    }

    /** Valida un enlace del formulario sin consumirlo. Devuelve su id. */
    public function findLink(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $link = $this->app->db->one('SELECT id FROM whatsapp_links WHERE token_hash=? AND used_at IS NULL AND expires_at>?', [hash('sha256', $token), $this->app->now()]);
        return $link ? (int)$link['id'] : null;
    }

    /** Vincula la solicitud al número que pidió el enlace. Debe ejecutarse dentro de la transacción de la solicitud. */
    public function attachLink(int $linkId, int $requestId): void
    {
        $link = $this->app->db->one('SELECT * FROM whatsapp_links WHERE id=? AND used_at IS NULL AND expires_at>? FOR UPDATE', [$linkId, $this->app->now()]);
        if (!$link) { $this->app->event($requestId, 'whatsapp_link', 'expired'); return; }
        $this->app->db->run('UPDATE whatsapp_links SET used_at=?,request_id=? WHERE id=?', [$this->app->now(), $requestId, $linkId]);
        $this->app->db->run('UPDATE requests SET whatsapp_phone=? WHERE id=?', [$link['phone'], $requestId]);
        $this->app->event($requestId, 'whatsapp_link', 'attached');
    }

    /** Encola la historia por WhatsApp si la solicitud vino del chat. */
    public function queueHistory(int $requestId, int $packageId): void
    {
        $request = $this->app->request($requestId);
        if (!$request['whatsapp_phone']) return;
        $this->queue($request['whatsapp_phone'], 'history', null, $requestId, $packageId, 'wa:history:' . $requestId);
    }

    /** Informa por WhatsApp el radicado de una solicitud sin historia disponible. */
    public function queueNotFound(int $requestId): void
    {
        $request = $this->app->request($requestId);
        if (!$request['whatsapp_phone']) return;
        $body = $this->templates()->render('pending', ['radicado' => $request['reference'], 'nombre' => $request['claimed_name'], 'horas' => (int)$this->app->config['whatsapp_estimated_hours']]);
        $this->queue($request['whatsapp_phone'], 'notice', $body, $requestId, null, 'wa:notice:' . $requestId);
    }

    /** Respuesta «No registra» de la persona encargada. Debe ejecutarse dentro de la transacción del cierre. */
    public function queueNotRegistered(int $requestId): void
    {
        $request = $this->app->request($requestId);
        if (!$request['whatsapp_phone']) return;
        $body = $this->templates()->render('not_registered', ['radicado' => $request['reference'], 'nombre' => $request['claimed_name'], 'enlace' => $this->portalUrl()]);
        $this->queue($request['whatsapp_phone'], 'not_registered', $body, $requestId, null, 'wa:not_registered:' . $requestId);
    }

    /**
     * Respuesta de la persona encargada (Aprobado / No Registra) con su comentario; los adjuntos se enlazan después
     * con wa_job_id. Devuelve el id del mensaje o null si la solicitud no tiene WhatsApp.
     */
    public function queueResponse(int $requestId, string $decision, string $comment, string $unique): ?int
    {
        $request = $this->app->request($requestId);
        if (!$request['whatsapp_phone']) return null;
        $kind = $decision === 'approved' ? 'response' : 'not_registered';
        $body = "Respuesta a tu solicitud *{$request['reference']}*.\n\n" . $comment;
        return $this->queue($request['whatsapp_phone'], $kind, $body, $requestId, null, 'wa:response:' . $requestId . ':' . $unique,
            $decision === 'approved' ? 'historia.disponible' : 'solicitud.no_registra');
    }

    /** Encola un texto libre (lo usa n8n para las respuestas del bot). Devuelve el id del mensaje en la cola. */
    public function queueText(string $phone, string $body, string $dedupe): int
    {
        return $this->queue($phone, 'text', $body, null, null, 'wa:' . hash('sha256', $dedupe));
    }

    private const EVENTS = ['history' => 'historia.disponible', 'notice' => 'solicitud.pendiente', 'not_registered' => 'solicitud.no_registra', 'text' => 'whatsapp.mensaje'];

    private function queue(string $phone, string $kind, ?string $body, ?int $requestId, ?int $packageId, string $dedupe, ?string $event = null): int
    {
        $new = $this->app->db->run('INSERT IGNORE INTO whatsapp_outbox (request_id,package_id,phone,kind,encrypted_body,available_at,created_at,dedupe_key) VALUES (?,?,?,?,?,?,?,?)', [
            $requestId, $packageId, $phone, $kind, $body === null ? null : Crypto::seal($body, $this->app->config['app_key']),
            $this->app->now(), $this->app->now(), $dedupe,
        ])->rowCount();
        $id = (int)$this->app->db->one('SELECT id FROM whatsapp_outbox WHERE dedupe_key=?', [$dedupe])['id'];
        // n8n se entera de cada mensaje nuevo para coordinar su salida (y avisar al responsable de los pendientes).
        if ($new) $this->automation()->emit($event ?? self::EVENTS[$kind] ?? 'whatsapp.mensaje', ['job_id' => $id, 'kind' => $kind,
            'reference' => $requestId ? $this->app->request($requestId)['reference'] : null,
            'plazo_horas' => $kind === 'notice' ? (int)$this->app->config['whatsapp_estimated_hours'] : null], 'wa-job:' . $id);
        return $id;
    }

    private function automation(): Automation { return new Automation($this->app); }

    /** Procesa un mensaje pendiente (o el indicado, si lo pide n8n). Devuelve false si no había trabajo. */
    public function tick(?int $jobId = null): bool
    {
        $db = $this->app->db;
        $db->run("UPDATE whatsapp_outbox SET status='failed',last_error='window_expired',encrypted_body=NULL WHERE status='waiting_window' AND created_at<?", [date('Y-m-d H:i:s', time() - 86400 * (int)$this->app->config['whatsapp_window_wait_days'])]);
        $db->run("UPDATE whatsapp_outbox SET status='uncertain',last_error='worker_interrupted',encrypted_body=NULL WHERE status='sending' AND locked_at<?", [date('Y-m-d H:i:s', time() - 300)]);
        $job = $db->transaction(function () use ($db, $jobId) {
            $job = $jobId === null
                ? $db->one("SELECT * FROM whatsapp_outbox WHERE status IN ('queued','retry') AND available_at<=? ORDER BY id LIMIT 1 FOR UPDATE", [$this->app->now()])
                : $db->one("SELECT * FROM whatsapp_outbox WHERE id=? AND status IN ('queued','retry') FOR UPDATE", [$jobId]);
            if (!$job) return null;
            $db->run("UPDATE whatsapp_outbox SET status='sending',attempts=attempts+1,locked_at=? WHERE id=?", [$this->app->now(), $job['id']]);
            $job['attempts']++;
            return $job;
        });
        if (!$job) return false;
        try {
            $messages = $job['kind'] === 'history' ? $this->historyMessages($job) : [['type' => 'text', 'body' => Crypto::open($job['encrypted_body'], $this->app->config['app_key'])]];
            if (in_array($job['kind'], ['response', 'not_registered'], true)) $messages = array_merge($messages, $this->responseMessages($job));
            if ($this->app->config['whatsapp_transport'] === 'local') {
                $preview = array_map(fn($m) => match ($m['type']) {
                    'document' => ['type' => 'document', 'filename' => $m['filename'], 'caption' => $m['caption'] ?? null],
                    'image' => ['type' => 'image', 'page' => $m['page'], 'caption' => $m['caption'] ?? null],
                    'photo' => ['type' => 'photo', 'filename' => $m['filename'], 'mime' => $m['mime']],
                    default => $m,
                }, $messages);
                $path = $this->app->config['storage_path'] . '/wa-outbox/' . $job['id'] . '.json';
                if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
                file_put_contents($path, json_encode(['to' => $job['phone'], 'messages' => $preview], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                $this->finish($job, 'simulated');
                return true;
            }
            $transport = $this->app->config['whatsapp_transport'];
            $config = $this->app->config;
            $configured = match ($transport) {
                'cloud' => $config['whatsapp_token'] && $config['whatsapp_phone_number_id'],
                'zernio' => $config['zernio_api_key'] && $config['zernio_account_id'] && $config['zernio_api_url'],
                'qr' => $config['whatsapp_app_secret'] && $config['whatsapp_qr_url'],
                default => false,
            };
            if (!$configured) throw new \DomainException('whatsapp_not_configured');
            // progress conserva los documentos ya aceptados para no repetirlos en un reintento.
            foreach ($messages as $index => $message) {
                if ($index < (int)$job['progress']) continue;
                match ($transport) {
                    'qr' => $this->deliverQr($job['phone'], $message),
                    // La clave incluye la instalación: otra base con el mismo id de cola no choca en Zernio.
                    'zernio' => $this->deliverZernio($job['phone'], $message, 'citaya-' . substr(hash('sha256', (string)$config['app_key']), 0, 8) . '-' . $job['id'] . '-' . $index),
                    default => $this->deliver($job['phone'], $message),
                };
                $db->run('UPDATE whatsapp_outbox SET progress=? WHERE id=?', [$index + 1, $job['id']]);
            }
            $this->finish($job, 'sent');
        } catch (\DomainException $error) {
            $this->finish($job, 'failed', $error->getMessage());
        } catch (WhatsAppTransportError $error) {
            if ($error->getMessage() === 'outside_24h_window') { $this->waitForWindow($job); return true; }
            $this->finish($job, $error->retry && $job['attempts'] < $this->app->config['mail_attempts'] ? 'retry' : 'failed', $error->getMessage());
        }
        return true;
    }

    /** Adjuntos de una respuesta de la persona encargada: PDF como documento y JPG/PNG como imagen. */
    private function responseMessages(array $job): array
    {
        if (!$job['request_id']) return [];
        $request = $this->app->request((int)$job['request_id']);
        if (!$request['whatsapp_phone'] || !hash_equals($request['whatsapp_phone'], $job['phone'])) throw new \DomainException('recipient_changed');
        $messages = [];
        foreach ($this->app->responseFiles((int)$job['id'], 'wa_job_id') as $file) {
            $messages[] = $file['mime'] === 'application/pdf'
                ? ['type' => 'document', 'path' => $file['path'], 'filename' => $file['original_name'], 'caption' => null]
                : ['type' => 'photo', 'path' => $file['path'], 'filename' => $file['original_name'], 'mime' => $file['mime']];
        }
        return $messages;
    }

    private function historyMessages(array $job): array
    {
        $request = $this->app->request((int)$job['request_id']);
        if (!$request['whatsapp_phone'] || !hash_equals($request['whatsapp_phone'], $job['phone'])) throw new \DomainException('recipient_changed');
        $package = $this->app->db->one('SELECT p.*,e.patient_id FROM packages p JOIN encounters e ON e.id=p.encounter_id WHERE p.id=?', [$job['package_id']]);
        if (!$package || (int)$package['patient_id'] !== (int)$request['patient_id'] || (int)$package['encounter_id'] !== (int)$request['encounter_id']) throw new \DomainException('package_mismatch');
        $this->app->requireVerified((int)$request['id']);
        $files = $this->app->packageFiles((int)$job['package_id']);
        $messages = [['type' => 'text', 'body' => $this->templates()->render('history_found', ['radicado' => $request['reference'], 'nombre' => $request['claimed_name'], 'documentos' => count($files), 'correo' => $request['contact_email']])]];
        foreach ($files as $index => $file) {
            $messages[] = ['type' => 'document', 'path' => $file['path'], 'filename' => sprintf('Historia_%s_%02d.pdf', $request['reference'], $index + 1),
                'caption' => sprintf('Documento %d de %d', $index + 1, count($files))];
        }
        return array_merge($messages, $this->orderMessages($files));
    }

    /**
     * Órdenes de la historia: un aviso y, por cada página con órdenes, la imagen de esa página con las indicaciones
     * de qué hacer con cada orden (plantillas «Orden: …», editables). Si la imagen no se puede generar al enviar,
     * las mismas indicaciones salen como texto. Son orientaciones administrativas, no reemplazan al especialista.
     */
    private function orderMessages(array $files): array
    {
        $position = [];
        foreach ($files as $index => $file) $position[(int)$file['id']] = $index + 1;
        $orders = $this->app->db->all('SELECT file_id,order_type,page FROM clinical_orders WHERE file_id IN (' . implode(',', array_keys($position)) . ') ORDER BY file_id,page,id');
        if (!$orders) return [];
        $pages = [];
        foreach ($orders as $order) $pages[$order['file_id'] . ':' . $order['page']][$order['order_type']] = true;
        $count = count($orders);
        $messages = [['type' => 'text', 'body' => 'En tu historia vienen *' . $count . '* ' . ($count === 1 ? 'orden' : 'órdenes') . ' de tu especialista. '
            . "Te enviamos cada una con lo que debes hacer.\n\nEstas indicaciones son administrativas: sigue siempre lo que te indicó tu médico y, si tienes dudas, consulta en tu EPS."]];
        $filesById = array_column($files, null, 'id');
        foreach (array_slice($pages, 0, 8, true) as $key => $types) {
            [$fileId, $page] = array_map('intval', explode(':', (string)$key));
            $where = count($files) > 1 ? 'Documento ' . $position[$fileId] . ', página ' . $page : 'Página ' . $page;
            $parts = [];
            foreach (array_keys($types) as $type) {
                $guide = isset(MessageTemplates::DEFINITIONS['order_' . $type]) ? $this->templates()->render('order_' . $type) : MedicalOrders::guidance($type);
                $parts[] = '📄 *' . MedicalOrders::label($type) . "*\n" . $guide;
            }
            $caption = mb_substr(implode("\n\n", $parts) . "\n\n_" . $where . ' de tu historia_', 0, 1000);
            $messages[] = ['type' => 'image', 'path' => $filesById[$fileId]['path'], 'page' => $page, 'caption' => $caption];
        }
        return $messages;
    }

    private function deliver(string $phone, array $message): void
    {
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $phone];
        if ($message['type'] === 'document') {
            $media = $this->call('media', [
                'messaging_product' => 'whatsapp', 'type' => 'application/pdf',
                'file' => new \CURLFile($message['path'], 'application/pdf', $message['filename']),
            ]);
            if (empty($media['id'])) throw new WhatsAppTransportError('media_upload_without_id', true);
            $payload += ['type' => 'document', 'document' => ['id' => $media['id'], 'filename' => $message['filename'], 'caption' => $message['caption']]];
        } elseif ($message['type'] === 'photo') {
            $media = $this->call('media', ['messaging_product' => 'whatsapp', 'type' => $message['mime'], 'file' => new \CURLFile($message['path'], $message['mime'], $message['filename'])]);
            if (empty($media['id'])) throw new WhatsAppTransportError('media_upload_without_id', true);
            $payload += ['type' => 'image', 'image' => ['id' => $media['id']]];
        } elseif ($message['type'] === 'image') {
            // La página se convierte en el momento del envío; si no se puede, la guía ya indica dónde está.
            $png = PdfPageImage::render($message['path'], $message['page']);
            if ($png === null) { $this->deliver($phone, ['type' => 'text', 'body' => $message['caption']]); return; }
            try {
                $media = $this->call('media', ['messaging_product' => 'whatsapp', 'type' => 'image/png', 'file' => new \CURLFile($png, 'image/png', 'orden.png')]);
            } finally { @unlink($png); }
            if (empty($media['id'])) throw new WhatsAppTransportError('media_upload_without_id', true);
            $payload += ['type' => 'image', 'image' => ['id' => $media['id'], 'caption' => $message['caption']]];
        } else {
            $payload += ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $message['body']]];
        }
        $this->call('messages', $payload);
    }

    /**
     * Transporte de pruebas: servicio local tools/whatsapp-qr (WhatsApp Web vinculado por QR a un número secundario).
     * No es oficial; solo para datos ficticios. La petición va firmada con whatsapp_app_secret.
     */
    private function deliverQr(string $phone, array $message): void
    {
        $payload = ['to' => $phone, 'type' => $message['type']];
        if ($message['type'] === 'document') {
            $payload += ['data' => base64_encode((string)file_get_contents($message['path'])), 'filename' => $message['filename'], 'caption' => $message['caption']];
        } elseif ($message['type'] === 'photo') {
            $payload = ['to' => $phone, 'type' => 'image', 'data' => base64_encode((string)file_get_contents($message['path'])), 'filename' => $message['filename'], 'mimetype' => $message['mime']];
        } elseif ($message['type'] === 'image') {
            $png = PdfPageImage::render($message['path'], $message['page']);
            if ($png === null) { $this->deliverQr($phone, ['type' => 'text', 'body' => $message['caption']]); return; }
            try { $payload += ['data' => base64_encode((string)file_get_contents($png)), 'filename' => 'orden.png', 'caption' => $message['caption']]; }
            finally { @unlink($png); }
        } else {
            $payload['body'] = $message['body'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $curl = curl_init(rtrim($this->app->config['whatsapp_qr_url'], '/') . '/send');
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Signature: sha256=' . hash_hmac('sha256', $body, $this->app->config['whatsapp_app_secret'])],
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($raw === false) throw new WhatsAppTransportError('qr_service_unreachable_' . $errno, true);
        if ($status === 200) return;
        $result = (string)(json_decode((string)$raw, true)['result'] ?? 'http_' . $status);
        // 503: el teléfono se desconectó; 502: WhatsApp Web falló. Ambos se reintentan.
        throw new WhatsAppTransportError('qr_' . preg_replace('/[^a-z0-9_]/', '', $result), in_array($status, [502, 503], true));
    }

    /**
     * Transporte Zernio: responde en la conversación que abrió el paciente. Los archivos van como
     * multipart (el nombre del CURLFile es el que ve el paciente). La clave de idempotencia evita
     * duplicados si se pierde la respuesta de un envío que sí salió.
     */
    private function deliverZernio(string $phone, array $message, string $idempotencyKey): void
    {
        $conversation = $this->app->db->one('SELECT conversation_id FROM whatsapp_conversations WHERE phone=?', [$phone]);
        if (!$conversation) throw new \DomainException('zernio_no_conversation');
        $path = '/v1/inbox/conversations/' . rawurlencode($conversation['conversation_id']) . '/messages';
        $account = $this->app->config['zernio_account_id'];
        if ($message['type'] === 'document') {
            $this->zernioCall($path, ['accountId' => $account, 'message' => $message['caption'], 'attachment' => new \CURLFile($message['path'], 'application/pdf', $message['filename'])], $idempotencyKey);
        } elseif ($message['type'] === 'photo') {
            $this->zernioCall($path, ['accountId' => $account, 'attachment' => new \CURLFile($message['path'], $message['mime'], $message['filename'])], $idempotencyKey);
        } elseif ($message['type'] === 'image') {
            $png = PdfPageImage::render($message['path'], $message['page']);
            if ($png === null) { $this->deliverZernio($phone, ['type' => 'text', 'body' => $message['caption']], $idempotencyKey); return; }
            try { $this->zernioCall($path, ['accountId' => $account, 'message' => $message['caption'], 'attachment' => new \CURLFile($png, 'image/png', 'orden.png')], $idempotencyKey); }
            finally { @unlink($png); }
        } else {
            $this->zernioCall($path, ['accountId' => $account, 'message' => $message['body']], $idempotencyKey, true);
        }
    }

    private function zernioCall(string $path, array $fields, ?string $idempotencyKey = null, bool $json = false): array
    {
        $config = $this->app->config;
        $curl = curl_init(rtrim($config['zernio_api_url'], '/') . $path);
        $headers = ['Authorization: Bearer ' . $config['zernio_api_key'], 'Accept: application/json'];
        if ($json) $headers[] = 'Content-Type: application/json';
        if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json ? json_encode($fields, JSON_UNESCAPED_UNICODE) : $fields,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($raw === false) throw new WhatsAppTransportError('zernio_network_error_' . $errno, true);
        $response = json_decode((string)$raw, true) ?: [];
        if ($status >= 200 && $status < 300) return $response;
        // Zernio reenvía el código de Meta en platformError; 131047 = ventana de 24 h cerrada.
        $metaCode = (int)($response['platformError']['code'] ?? 0);
        if ($metaCode === 131047) throw new WhatsAppTransportError('outside_24h_window', false);
        $code = preg_replace('/[^A-Za-z0-9_]/', '', (string)($response['code'] ?? '')) ?: 'error';
        // 409: la misma clave de idempotencia sigue en proceso; 429 y 5xx: límite o falla temporal.
        throw new WhatsAppTransportError(substr('zernio_' . $status . '_' . $code . ($metaCode ? '_' . $metaCode : ''), 0, 120), in_array($status, [409, 429], true) || $status >= 500);
    }

    private function call(string $endpoint, array $fields): array
    {
        $config = $this->app->config;
        $url = 'https://graph.facebook.com/' . $config['whatsapp_api_version'] . '/' . rawurlencode($config['whatsapp_phone_number_id']) . '/' . $endpoint;
        $json = $endpoint === 'messages';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $config['whatsapp_token']], $json ? ['Content-Type: application/json'] : []),
            CURLOPT_POSTFIELDS => $json ? json_encode($fields, JSON_UNESCAPED_UNICODE) : $fields,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($raw === false) throw new WhatsAppTransportError('network_error_' . $errno, true);
        $response = json_decode((string)$raw, true) ?: [];
        if ($status >= 200 && $status < 300) return $response;
        $code = (int)($response['error']['code'] ?? 0);
        // 131047: pasaron más de 24 horas desde el último mensaje del paciente.
        if ($code === 131047) throw new WhatsAppTransportError('outside_24h_window', false);
        throw new WhatsAppTransportError('http_' . $status . '_code_' . $code, $status === 429 || $status >= 500);
    }

    /**
     * Pasaron más de 24 h desde el último mensaje del paciente: WhatsApp solo admite plantillas.
     * El mensaje espera (conservando lo ya enviado) y sale cuando el paciente vuelva a escribir.
     */
    private function waitForWindow(array $job): void
    {
        $this->finish($job, 'waiting_window', 'outside_24h_window');
        $template = (string)$this->app->config['whatsapp_template_ready'];
        if ($template === '' || !$job['request_id'] || !in_array($job['kind'], ['history', 'notice', 'not_registered', 'response'], true)) return;
        $reference = $this->app->request((int)$job['request_id'])['reference'];
        try {
            if ($this->app->config['whatsapp_transport'] === 'zernio') {
                // Con una conversación existente, Zernio envía la plantilla dentro de ella.
                $this->zernioCall('/v1/inbox/conversations', [
                    'accountId' => $this->app->config['zernio_account_id'], 'participantId' => $job['phone'],
                    'templateName' => $template, 'templateLanguage' => $this->app->config['whatsapp_template_lang'], 'templateParams' => [$reference],
                ], 'citaya-template-' . substr(hash('sha256', (string)$this->app->config['app_key']), 0, 8) . '-' . $job['id'], true);
            } else {
                $this->call('messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $job['phone'], 'type' => 'template', 'template' => [
                    'name' => $template, 'language' => ['code' => $this->app->config['whatsapp_template_lang']],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $reference]]]],
                ]]);
            }
        } catch (WhatsAppTransportError) { $this->app->event((int)$job['request_id'], 'whatsapp_template', 'failed'); }
    }

    private function finish(array $job, string $status, ?string $error = null): void
    {
        $this->app->db->run('UPDATE whatsapp_outbox SET status=?,last_error=?,sent_at=?,available_at=?,encrypted_body=CASE WHEN ?=1 THEN NULL ELSE encrypted_body END WHERE id=?', [
            $status, $error, in_array($status, ['sent','simulated'], true) ? $this->app->now() : null,
            date('Y-m-d H:i:s', time() + 60 * (int)$job['attempts']), in_array($status, ['retry', 'waiting_window'], true) ? 0 : 1, $job['id'],
        ]);
        if ($job['request_id']) $this->app->event((int)$job['request_id'], 'whatsapp_' . $job['kind'], $status);
        if (in_array($status, ['failed', 'uncertain'], true)) $this->automation()->emit('envio.fallido', ['canal' => 'whatsapp', 'job_id' => (int)$job['id'], 'kind' => $job['kind'],
            'reference' => $job['request_id'] ? $this->app->request((int)$job['request_id'])['reference'] : null, 'error' => $error], 'wa-fail:' . $job['id'] . ':' . $job['attempts']);
    }
}
