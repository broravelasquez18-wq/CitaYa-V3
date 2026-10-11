<?php
declare(strict_types=1);
namespace App;

final class AdminDeliveries
{
    /** $appKey: para mostrar el comentario de respuesta (cifrado) en el detalle. */
    public function __construct(private readonly Database $db, private readonly string $appKey = '') {}

    private function pendingSql(): string
    {
        return "(r.case_reopened_after_job_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jobs resolved WHERE resolved.request_id=r.id AND resolved.kind IN ('history','response') AND resolved.status='sent' AND resolved.sent_at IS NOT NULL AND resolved.id>r.case_reopened_after_job_id))";
    }

    public function reopen(int $id, int $adminId, int $expectedJobId): void
    {
        $this->db->transaction(function () use ($id, $adminId, $expectedJobId) {
            if (!$this->db->one('SELECT id FROM admins WHERE id=? AND active=1', [$adminId])) throw new \DomainException('Se requiere un administrador activo.');
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$id]);
            if (!$request || (!$request['matched_at'] && !$request['verified_at'])) throw new \DomainException('El caso no está disponible para reabrir.');
            $jobs = $this->db->all("SELECT id,status,sent_at FROM jobs WHERE request_id=? AND kind IN ('history','response') ORDER BY id DESC FOR UPDATE", [$id]);
            if (!$jobs || (int)$jobs[0]['id'] !== $expectedJobId) throw new \DomainException('El envío cambió. Actualiza el historial antes de reabrir.');
            $closed = false;
            foreach ($jobs as $job) {
                if (in_array($job['status'], ['queued','sending','retry'], true)) throw new \DomainException('Espera a que termine el envío en curso antes de reabrir el caso.');
                if ($job['status']==='sent' && $job['sent_at'] && (int)$job['id']>(int)$request['case_reopened_after_job_id']) $closed=true;
            }
            if (!$closed) throw new \DomainException('El caso ya está pendiente o todavía no tiene un envío completado.');
            $now = date('Y-m-d H:i:s');
            $this->db->run('UPDATE requests SET case_reopened_at=?,case_reopened_by=?,case_reopened_after_job_id=? WHERE id=?', [$now,$adminId,$expectedJobId,$id]);
            $this->db->insert('events', ['request_id'=>$id,'action'=>'case_reopened','result'=>'admin_'.$adminId,'created_at'=>$now]);
        });
    }

    /** Solicitudes que esperan gestión de la persona encargada (sin historia enviada ni respuesta de cierre). */
    private function awaitingSql(): string
    {
        return "((r.direct_search=1 AND r.status IN ('" . implode("','", Application::PENDING_STATUSES) . "')) OR " . $this->pendingSql() . ")";
    }

    public function pendingCount(): int
    {
        return (int)$this->db->one('SELECT COUNT(*) n FROM requests r WHERE ' . $this->awaitingSql())['n'];
    }

    public function search(string $query, int $page = 1, bool $history = false, bool $pendingOnly = false): array
    {
        $query = mb_substr(trim($query), 0, 160);
        $where = '';
        $params = [];
        if ($query !== '') {
            $term = '%' . strtr($query, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
            $where = " WHERE (r.reference LIKE ? ESCAPE '!' OR r.claimed_name LIKE ? ESCAPE '!' OR p.full_name LIKE ? ESCAPE '!' OR r.claimed_document_number LIKE ? ESCAPE '!' OR p.document_number LIKE ? ESCAPE '!')";
            $params = array_fill(0, 5, $term);
        }
        if ($history) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . "(r.matched_at IS NOT NULL OR r.verified_at IS NOT NULL)
                AND EXISTS (SELECT 1 FROM jobs delivered WHERE delivered.request_id=r.id AND delivered.kind IN ('history','response') AND delivered.status='sent' AND delivered.sent_at IS NOT NULL AND delivered.id>COALESCE(r.case_reopened_after_job_id,0))";
        }
        if ($pendingOnly && !$history) $where .= ($where === '' ? ' WHERE ' : ' AND ') . $this->awaitingSql();
        $from = ' FROM requests r LEFT JOIN patients p ON p.id=r.patient_id';
        $total = (int)$this->db->one('SELECT COUNT(*) n' . $from . $where, $params)['n'];
        $pages = max(1, (int)ceil($total / 20));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * 20;
        $rows = $this->db->all("SELECT r.*,COALESCE(r.claimed_document_type,p.document_type) document_type,
            COALESCE(r.claimed_document_number,p.document_number) document_number,
            j.id last_job_id,j.status delivery_status,j.sent_at,j.recipient,j.last_error,e.attended_at,s.name specialty,
            " . $this->pendingSql() . " case_pending," . $this->awaitingSql() . " awaiting,
            (SELECT MIN(done.sent_at) FROM jobs done WHERE done.request_id=r.id AND done.kind IN ('history','response') AND done.status='sent' AND done.id>COALESCE(r.case_reopened_after_job_id,0)) closed_at,
            (SELECT COUNT(*) FROM jobs n WHERE n.request_id=r.id AND n.kind IN ('history','response')) deliveries"
            . $from . " LEFT JOIN encounters e ON e.id=r.encounter_id LEFT JOIN specialties s ON s.id=e.specialty_id
                LEFT JOIN jobs j ON j.id=(SELECT MAX(last.id) FROM jobs last WHERE last.request_id=r.id AND last.kind IN ('history','response'))"
            . $where . ($history ? ' ORDER BY closed_at DESC,r.id DESC' : ($pendingOnly ? ' ORDER BY r.created_at ASC,r.id ASC' : ' ORDER BY awaiting DESC,case_pending DESC,r.id DESC')) . " LIMIT 20 OFFSET $offset", $params);
        return compact('query','total','page','pages','rows','history','pendingOnly');
    }

    /**
     * Línea de tiempo del caso para «Historial del caso»: radicación, búsqueda automática, respuesta de la persona
     * encargada, reapertura y cada envío por correo y WhatsApp, del más antiguo al más reciente.
     * Cada entrada: at, icon, title, status, text, by, notified. Solo metadatos y el comentario de respuesta.
     */
    public function timeline(int $id): ?array
    {
        $detail = $this->detail($id);
        if (!$detail) return null;
        $r = $detail['request'];
        $entries = [];
        $add = static function (?string $at, string $icon, string $title, string $status, string $text, string $by, ?string $notified = null) use (&$entries): void {
            if ($at) $entries[] = compact('at', 'icon', 'title', 'status', 'text', 'by', 'notified');
        };
        $events = $this->db->all('SELECT action,result,created_at FROM events WHERE request_id=? ORDER BY id', [$id]);
        $viaWhatsApp = (bool)array_filter($events, fn($e) => $e['action'] === 'whatsapp_link' && $e['result'] === 'attached');
        $date = $r['approximate_date'] ? 'Fecha de atención indicada: ' . date('d/m/Y', strtotime($r['approximate_date'])) . '.' : 'El paciente no recordaba la fecha de atención.';
        $add($r['created_at'], 'web', 'Radicación', 'pending',
            'Solicitud de historia clínica radicada por el paciente ' . ($viaWhatsApp ? 'con el enlace que le envió el bot de WhatsApp' : 'desde el formulario web') . '. ' . $date
            . ($r['specialty'] ? ' Especialidad: ' . $r['specialty'] . '.' : ''), 'Paciente', trim(($r['contact_email'] ?? '') . ($r['whatsapp_phone'] ? ' · +' . $r['whatsapp_phone'] : ''), ' ·'));
        foreach ($events as $event) {
            if ($event['action'] !== 'pdf_search') continue;
            [$status, $text] = match ($event['result']) {
                'single_match' => ['ok', 'Se encontró la historia clínica en el repositorio y se preparó el envío automático.'],
                'multiple_matches' => ['info', 'Se encontraron varias atenciones posibles; el paciente debe elegir la suya.'],
                'patient_not_found' => ['pending', 'No hay un paciente con ese documento y nombre en el repositorio. La solicitud quedó pendiente para la persona encargada.'],
                'not_eligible' => ['pending', 'El paciente existe, pero no se habilitó la entrega automática al correo indicado. La solicitud quedó pendiente.'],
                default => ['pending', 'No se encontró una historia cargada con los datos indicados. La solicitud quedó pendiente para la persona encargada.'],
            };
            $add($event['created_at'], 'search', 'Búsqueda automática', $status, $text, 'Sistema');
        }
        if ($r['resolution']) {
            $titles = ['approved' => 'Aprobado', 'not_registered' => 'No Registra', 'assigned' => 'Historia asignada'];
            $text = $r['response_note'] ?? ($r['resolution'] === 'assigned' ? 'La persona encargada asignó la historia del repositorio a esta solicitud.' : 'Se respondió «No registra» con la plantilla.');
            $add($r['resolved_at'], 'response', 'Respuesta de la persona encargada', $r['resolution'] === 'not_registered' ? 'fail' : 'ok', $text, $r['resolved_by_name'] ?? 'Administrador');
            $entries[count($entries) - 1]['badge'] = $titles[$r['resolution']] ?? $r['resolution'];
        }
        if ($r['case_reopened_at']) {
            $by = $this->db->one('SELECT name FROM admins WHERE id=?', [$r['case_reopened_by']])['name'] ?? 'Administrador';
            $add($r['case_reopened_at'], 'reopen', 'Caso reabierto', 'pending', 'El caso volvió a pendientes. Los envíos anteriores se conservan.', $by);
        }
        $mailTitles = ['history' => 'Envío de historia clínica por correo', 'response' => 'Respuesta «Aprobado» por correo', 'notice' => 'Respuesta «No Registra» por correo'];
        $mailJobs = $this->db->all("SELECT j.id,j.kind,j.recipient,j.status,j.created_at,j.sent_at,j.last_error FROM jobs j WHERE j.request_id=? AND j.kind IN ('history','response','notice') ORDER BY j.id", [$id]);
        foreach ($mailJobs as $job) {
            $files = $job['kind'] === 'history'
                ? $this->db->all('SELECT f.original_name FROM clinical_files f JOIN jobs j ON j.package_id=f.package_id WHERE j.id=? ORDER BY f.sort_order,f.id', [$job['id']])
                : $this->db->all('SELECT original_name FROM response_files WHERE job_id=? ORDER BY sort_order,id', [$job['id']]);
            $text = $files ? count($files) . ' archivo(s): ' . implode(', ', array_column($files, 'original_name')) . '.' : 'Sin archivos adjuntos.';
            if ($job['sent_at']) $text .= ' Aceptado por el servidor el ' . date('d/m/Y h:i a', strtotime($job['sent_at'])) . '.';
            if ($job['last_error']) $text .= ' Motivo registrado: ' . $job['last_error'] . '.';
            $add($job['created_at'], 'mail', $mailTitles[$job['kind']], $job['status'], $text, $job['kind'] === 'history' && $r['resolution'] !== 'assigned' ? 'Sistema' : ($r['resolved_by_name'] ?? 'Sistema'), $job['recipient']);
        }
        $waTitles = ['history' => 'Historia clínica por WhatsApp', 'notice' => 'Radicado por WhatsApp (sin historia cargada)', 'not_registered' => 'Respuesta «No Registra» por WhatsApp', 'response' => 'Respuesta «Aprobado» por WhatsApp'];
        foreach ($detail['whatsapp'] as $message) {
            $text = $message['sent_at'] ? 'WhatsApp aceptó el mensaje el ' . date('d/m/Y h:i a', strtotime($message['sent_at'])) . '.' : 'Intentos: ' . (int)$message['attempts'] . '.';
            if ($message['last_error']) $text .= ' Motivo registrado: ' . $message['last_error'] . '.';
            $add($message['created_at'], 'whatsapp', $waTitles[$message['kind']] ?? 'Mensaje de WhatsApp', $message['status'], $text, in_array($message['kind'], ['response', 'not_registered'], true) && $r['resolution'] ? ($r['resolved_by_name'] ?? 'Administrador') : 'Sistema', '+' . $message['phone']);
        }
        usort($entries, fn($a, $b) => strcmp($a['at'], $b['at']));
        return ['request' => $r, 'entries' => $entries];
    }

    public function detail(int $id): ?array
    {
        $request = $this->db->one('SELECT r.*,' . $this->pendingSql() . ' case_pending,' . $this->awaitingSql() . ' awaiting,COALESCE(r.claimed_document_type,p.document_type) document_type,
            COALESCE(r.claimed_document_number,p.document_number) document_number,
            p.full_name indexed_name,e.attended_at,s.name specialty,a.name resolved_by_name
            FROM requests r LEFT JOIN patients p ON p.id=r.patient_id LEFT JOIN admins a ON a.id=r.resolved_by
            LEFT JOIN encounters e ON e.id=r.encounter_id LEFT JOIN specialties s ON s.id=COALESCE(e.specialty_id,r.specialty_id)
            WHERE r.id=?', [$id]);
        if (!$request) return null;
        // Only delivery metadata is exposed: no OTPs or encrypted verification payloads.
        $deliveries = $this->db->all("SELECT id,kind,recipient,status,attempts,created_at,locked_at,sent_at,message_id,last_error,package_id
            FROM jobs WHERE request_id=? AND (kind IN ('history','response') OR (kind='notice' AND dedupe_key LIKE 'response:%')) ORDER BY id DESC", [$id]);
        foreach ($deliveries as &$delivery) {
            // Historias: los PDF del paquete; respuestas de la persona encargada: sus adjuntos.
            $delivery['files'] = $delivery['kind'] === 'history'
                ? $this->db->all('SELECT original_name,page_count,size_bytes FROM clinical_files WHERE package_id=? ORDER BY sort_order,id', [$delivery['package_id']])
                : $this->db->all('SELECT original_name,NULL page_count,size_bytes FROM response_files WHERE job_id=? ORDER BY sort_order,id', [$delivery['id']]);
        }
        try { $request['response_note'] = $request['response_note'] && $this->appKey !== '' ? Crypto::open($request['response_note'], $this->appKey) : null; }
        catch (\Throwable) { $request['response_note'] = null; }
        // Solo metadatos: el texto cifrado del mensaje no se expone.
        $whatsapp = $this->db->all("SELECT id,phone,kind,status,attempts,progress,created_at,sent_at,last_error FROM whatsapp_outbox WHERE request_id=? ORDER BY id DESC", [$id]);
        return compact('request','deliveries','whatsapp');
    }
}
