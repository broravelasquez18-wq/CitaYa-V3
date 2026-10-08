<?php
declare(strict_types=1);
namespace App;

final class AdminDeliveries
{
    public function __construct(private readonly Database $db) {}

    private function pendingSql(): string
    {
        return "(r.case_reopened_after_job_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jobs resolved WHERE resolved.request_id=r.id AND resolved.kind='history' AND resolved.status='sent' AND resolved.sent_at IS NOT NULL AND resolved.id>r.case_reopened_after_job_id))";
    }

    public function reopen(int $id, int $adminId, int $expectedJobId): void
    {
        $this->db->transaction(function () use ($id, $adminId, $expectedJobId) {
            if (!$this->db->one('SELECT id FROM admins WHERE id=? AND active=1', [$adminId])) throw new \DomainException('Se requiere un administrador activo.');
            $request = $this->db->one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$id]);
            if (!$request || (!$request['matched_at'] && !$request['verified_at'])) throw new \DomainException('El caso no está disponible para reabrir.');
            $jobs = $this->db->all("SELECT id,status,sent_at FROM jobs WHERE request_id=? AND kind='history' ORDER BY id DESC FOR UPDATE", [$id]);
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
                AND EXISTS (SELECT 1 FROM jobs delivered WHERE delivered.request_id=r.id AND delivered.kind='history' AND delivered.status='sent' AND delivered.sent_at IS NOT NULL AND delivered.id>COALESCE(r.case_reopened_after_job_id,0))";
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
            (SELECT MIN(done.sent_at) FROM jobs done WHERE done.request_id=r.id AND done.kind='history' AND done.status='sent' AND done.id>COALESCE(r.case_reopened_after_job_id,0)) closed_at,
            (SELECT COUNT(*) FROM jobs n WHERE n.request_id=r.id AND n.kind='history') deliveries"
            . $from . " LEFT JOIN encounters e ON e.id=r.encounter_id LEFT JOIN specialties s ON s.id=e.specialty_id
                LEFT JOIN jobs j ON j.id=(SELECT MAX(last.id) FROM jobs last WHERE last.request_id=r.id AND last.kind='history')"
            . $where . ($history ? ' ORDER BY closed_at DESC,r.id DESC' : ($pendingOnly ? ' ORDER BY r.created_at ASC,r.id ASC' : ' ORDER BY awaiting DESC,case_pending DESC,r.id DESC')) . " LIMIT 20 OFFSET $offset", $params);
        return compact('query','total','page','pages','rows','history','pendingOnly');
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
        $deliveries = $this->db->all("SELECT id,recipient,status,attempts,created_at,locked_at,sent_at,message_id,last_error,package_id
            FROM jobs WHERE request_id=? AND kind='history' ORDER BY id DESC", [$id]);
        foreach ($deliveries as &$delivery) {
            $delivery['files'] = $this->db->all('SELECT original_name,page_count,size_bytes FROM clinical_files WHERE package_id=? ORDER BY sort_order,id', [$delivery['package_id']]);
        }
        // Solo metadatos: el texto cifrado del mensaje no se expone.
        $whatsapp = $this->db->all("SELECT id,phone,kind,status,attempts,progress,created_at,sent_at,last_error FROM whatsapp_outbox WHERE request_id=? ORDER BY id DESC", [$id]);
        return compact('request','deliveries','whatsapp');
    }
}
