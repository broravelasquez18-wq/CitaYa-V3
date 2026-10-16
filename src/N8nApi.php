<?php
declare(strict_types=1);
namespace App;

/**
 * Interfaz privada para n8n (public/n8n.php). n8n coordina la conversación; CitaYa sigue generando enlaces,
 * validando códigos y autorizando cada envío (pertenencia del paquete, integridad y destinatario).
 * Ninguna respuesta incluye contenido clínico: solo radicados, estados e identificadores.
 */
final class N8nApi
{
    /** Plantillas que n8n puede pedir para sus respuestas (se siguen editando en el panel). */
    private const TEMPLATES = ['menu', 'form_link'];

    private const LABELS = ['no_matches' => 'Pendiente de revisión', 'unavailable' => 'Pendiente de revisión', 'not_eligible' => 'Pendiente de revisión',
        'awaiting_selection' => 'Debes elegir la atención en la página', 'queued' => 'En preparación', 'sending' => 'En preparación', 'retry' => 'En preparación',
        'sent' => 'Enviada', 'simulated' => 'Enviada', 'failed' => 'Con un problema de envío; la revisará el personal', 'uncertain' => 'En revisión',
        'not_registered' => 'No registra', 'expired' => 'Vencida', 'cancelled' => 'Cancelada', 'pending_verification' => 'Pendiente de verificación', 'verified' => 'En preparación', 'matched' => 'En preparación'];

    public function __construct(private readonly Application $app) {}

    public function handle(array $payload): array
    {
        return match ((string)($payload['action'] ?? '')) {
            'inbound.register' => $this->registerInbound($payload),
            'conversation.handle' => $this->handleConversation($payload),
            'intake.start' => $this->startIntake($payload),
            'link.create' => $this->createLink($payload),
            'code.confirm' => $this->confirmCode($payload),
            'requests.list' => $this->listRequests($payload),
            'template.render' => $this->renderTemplate($payload),
            'message.send' => $this->sendMessage($payload),
            'delivery.pending' => $this->pendingDeliveries(),
            'delivery.send' => $this->sendDelivery($payload),
            'system.health' => $this->health(),
            'stats.summary' => $this->summary($payload),
            'requests.overdue' => $this->overdue(),
            default => throw new \DomainException('unknown_action'),
        };
    }

    private function phone(array $payload): string
    {
        $phone = preg_replace('/\D/', '', (string)($payload['phone'] ?? ''));
        if (strlen($phone) < 8 || strlen($phone) > 20) throw new \DomainException('invalid_phone');
        return $phone;
    }

    private function whatsapp(): WhatsApp { return $this->app->whatsapp(); }

    /**
     * Registra un mensaje recibido antes de procesarlo. new=false si es un reintento (mismo id): n8n no debe responder
     * dos veces. Libera los mensajes que esperaban porque el chat llevaba más de 24 h sin escribir.
     */
    private function registerInbound(array $payload): array
    {
        $phone = $this->phone($payload);
        $id = (string)($payload['message_id'] ?? '');
        if ($id === '' || strlen($id) > 190) throw new \DomainException('invalid_message_id');
        $new = $this->app->db->run('INSERT IGNORE INTO whatsapp_inbound (wa_message_id,phone,intent,created_at) VALUES (?,?,?,?)', [$id, $phone, 'n8n', $this->app->now()])->rowCount() > 0;
        if (!$new) return ['new' => false];
        // En un chat abierto con el personal no se limita la cantidad de mensajes.
        $chatting = (bool)$this->app->db->one("SELECT id FROM support_tickets WHERE phone=? AND status='open'", [$phone]);
        if (!$chatting) try { $this->app->limit('whatsapp-phone:' . $phone, 20, 3600); }
        catch (\DomainException) { return ['new' => true, 'allowed' => false]; }
        $released = $this->app->db->run("UPDATE whatsapp_outbox SET status='queued',last_error=NULL,available_at=? WHERE phone=? AND status='waiting_window'", [$this->app->now(), $phone])->rowCount();
        return ['new' => true, 'allowed' => true, 'released' => $released];
    }

    /**
     * Reporte de error y chat con el personal (SCRUM-47/48). handled=true: CitaYa ya respondió (o el mensaje es del
     * chat abierto) y n8n no debe seguir; false: n8n continúa con el bot normal (menú, HISTORIA, CONFIRMAR).
     */
    private function handleConversation(array $payload): array
    {
        $phone = $this->phone($payload);
        $id = (string)($payload['message_id'] ?? '');
        if ($id === '' || strlen($id) > 190) throw new \DomainException('invalid_message_id');
        return ['handled' => (new SupportDesk($this->app))->handle($phone, $id, mb_substr((string)($payload['text'] ?? ''), 0, 4000))];
    }

    /**
     * HISTORIA: el bot pide el número de documento y sigue la conversación (WhatsAppIntake); los pasos siguientes
     * llegan por conversation.handle. Si es la primera vez del documento, CitaYa envía el enlace del formulario.
     */
    private function startIntake(array $payload): array
    {
        $phone = $this->phone($payload);
        $id = (string)($payload['message_id'] ?? '');
        if ($id === '' || strlen($id) > 190) throw new \DomainException('invalid_message_id');
        (new WhatsAppIntake($this->app))->start($phone, $id);
        return ['started' => true];
    }

    /** Enlace personal al formulario (respaldo del bot si no se pudo iniciar la conversación). */
    private function createLink(array $payload): array
    {
        $phone = $this->phone($payload);
        $this->app->limit('n8n-link:' . $phone, 10, 3600);
        $link = $this->whatsapp()->createFormLink($phone);
        return ['url' => $link['url'], 'hours' => $link['hours']];
    }

    /** CONFIRMAR código: vincula el chat a la solicitud y encola la historia o el radicado si corresponde. */
    private function confirmCode(array $payload): array
    {
        $phone = $this->phone($payload);
        $code = strtoupper(trim((string)($payload['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) return ['valid' => false];
        $this->app->limit('whatsapp-confirm:' . $phone, 10, 3600);
        $result = $this->whatsapp()->confirmCode($phone, $code);
        return $result ? ['valid' => true] + $result : ['valid' => false];
    }

    /** Consulta de estado: solo las solicitudes vinculadas a ese chat. */
    private function listRequests(array $payload): array
    {
        $rows = $this->app->db->all('SELECT reference,status,created_at FROM requests WHERE whatsapp_phone=? ORDER BY id DESC LIMIT 5', [$this->phone($payload)]);
        return ['requests' => array_map(fn($r) => ['reference' => $r['reference'], 'status' => $r['status'], 'label' => self::LABELS[$r['status']] ?? 'En proceso', 'created_at' => $r['created_at']], $rows)];
    }

    private function renderTemplate(array $payload): array
    {
        $code = (string)($payload['code'] ?? '');
        if (!in_array($code, self::TEMPLATES, true)) throw new \DomainException('unknown_template');
        $values = array_map('strval', array_filter((array)($payload['values'] ?? []), 'is_scalar'));
        return ['text' => (new MessageTemplates($this->app))->render($code, $values)];
    }

    /**
     * Respuesta de texto del bot. Solo a chats que escribieron en las últimas 24 h o al responsable (admin_whatsapp),
     * para que n8n no pueda escribir a números arbitrarios. key evita duplicados si n8n reintenta.
     */
    private function sendMessage(array $payload): array
    {
        $phone = $this->phone($payload);
        $text = trim((string)($payload['text'] ?? ''));
        $key = (string)($payload['key'] ?? '');
        if ($text === '' || mb_strlen($text) > 4000) throw new \DomainException('invalid_text');
        if ($key === '' || strlen($key) > 190) throw new \DomainException('missing_key');
        $admin = preg_replace('/\D/', '', (string)($this->app->config['admin_whatsapp'] ?? ''));
        $recent = $this->app->db->one('SELECT 1 FROM whatsapp_inbound WHERE phone=? AND created_at>? LIMIT 1', [$phone, date('Y-m-d H:i:s', time() - 86400)]);
        if (!$recent && $phone !== $admin) throw new \DomainException('recipient_not_allowed');
        $jobId = $this->whatsapp()->queueText($phone, $text, 'n8n:' . $key);
        return $this->sendDelivery(['job_id' => $jobId]);
    }

    /** Monitoreo: procesador (latido), servicio QR y fallos recientes. Sin datos de pacientes. */
    private function health(): array
    {
        $beat = $this->app->db->one("SELECT updated_at FROM system_state WHERE name='worker_heartbeat'");
        $qr = null;
        if (($this->app->config['whatsapp_transport'] ?? '') === 'qr') {
            $curl = curl_init(rtrim((string)$this->app->config['whatsapp_qr_url'], '/') . '/health');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
            $qr = $raw !== false && $status === 200 ? (json_decode((string)$raw, true) ?: ['connected' => false]) : ['connected' => false, 'unreachable' => true];
        }
        $since = date('Y-m-d H:i:s', time() - 86400);
        return [
            'worker_seconds' => $beat ? time() - strtotime($beat['updated_at']) : null,
            'whatsapp_dispatch' => $this->app->config['whatsapp_dispatch'] ?? 'worker',
            'qr' => $qr,
            'failed_24h' => [
                'whatsapp' => (int)$this->app->db->one("SELECT COUNT(*) n FROM whatsapp_outbox WHERE status IN ('failed','uncertain') AND kind<>'text' AND created_at>=?", [$since])['n'],
                'correo' => (int)$this->app->db->one("SELECT COUNT(*) n FROM jobs WHERE status IN ('failed','uncertain') AND created_at>=?", [$since])['n'],
                'eventos' => (int)$this->app->db->one("SELECT COUNT(*) n FROM automation_events WHERE status='failed' AND created_at>=?", [$since])['n'],
            ],
        ];
    }

    private function pendingWhere(): string
    {
        return "direct_search=1 AND status IN ('" . implode("','", Application::PENDING_STATUSES) . "')";
    }

    /** Cantidades de un día (por defecto, ayer) y pendientes actuales. Sin nombres ni documentos. */
    private function summary(array $payload): array
    {
        $date = (string)($payload['date'] ?? date('Y-m-d', strtotime('-1 day')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \DomainException('invalid_date');
        [$from, $to] = [$date . ' 00:00:00', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00'];
        $hours = (int)$this->app->config['whatsapp_estimated_hours'];
        $db = $this->app->db;
        return [
            'date' => $date,
            'created' => (int)$db->one('SELECT COUNT(*) n FROM requests WHERE created_at>=? AND created_at<?', [$from, $to])['n'],
            'from_whatsapp' => (int)$db->one('SELECT COUNT(*) n FROM requests WHERE whatsapp_phone IS NOT NULL AND created_at>=? AND created_at<?', [$from, $to])['n'],
            'delivered' => (int)$db->one("SELECT COUNT(DISTINCT request_id) n FROM jobs WHERE kind='history' AND status IN ('sent','simulated') AND sent_at>=? AND sent_at<?", [$from, $to])['n'],
            'pending_now' => (int)$db->one('SELECT COUNT(*) n FROM requests WHERE ' . $this->pendingWhere())['n'],
            'overdue_now' => (int)$db->one('SELECT COUNT(*) n FROM requests WHERE ' . $this->pendingWhere() . ' AND created_at<?', [date('Y-m-d H:i:s', time() - 3600 * $hours)])['n'],
        ];
    }

    /** Pendientes cerca del plazo informado al paciente (desde 3/4 del plazo) o vencidos. */
    private function overdue(): array
    {
        $hours = (int)$this->app->config['whatsapp_estimated_hours'];
        $rows = $this->app->db->all('SELECT reference,created_at FROM requests WHERE ' . $this->pendingWhere() . ' AND created_at<? ORDER BY created_at LIMIT 50', [date('Y-m-d H:i:s', time() - (int)(2700 * $hours))]);
        return ['hours' => $hours, 'requests' => array_map(function ($r) use ($hours) {
            $age = intdiv(time() - strtotime($r['created_at']), 3600);
            return ['reference' => $r['reference'], 'age_hours' => $age, 'stage' => $age >= $hours ? 'vencida' : 'por_vencer'];
        }, $rows)];
    }

    /** Mensajes listos para salir (para recuperar los que n8n no alcanzó a enviar). */
    private function pendingDeliveries(): array
    {
        $rows = $this->app->db->all("SELECT o.id,o.kind,r.reference FROM whatsapp_outbox o LEFT JOIN requests r ON r.id=o.request_id
            WHERE o.status IN ('queued','retry') AND o.available_at<=? ORDER BY o.id LIMIT 50", [$this->app->now()]);
        return ['jobs' => array_map(fn($r) => ['job_id' => (int)$r['id'], 'kind' => $r['kind'], 'reference' => $r['reference']], $rows)];
    }

    /**
     * Envía un mensaje de la cola por el servicio QR. CitaYa arma y autoriza el contenido (historia, guía, radicado)
     * y conserva el progreso por archivo: un reintento no repite lo ya aceptado.
     */
    private function sendDelivery(array $payload): array
    {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId <= 0) throw new \DomainException('invalid_job');
        $before = $this->app->db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$jobId]);
        if (!$before) throw new \DomainException('job_not_found');
        if (in_array($before['status'], ['queued', 'retry'], true)) $this->whatsapp()->tick($jobId);
        $job = $this->app->db->one('SELECT id,kind,status,progress,attempts,last_error FROM whatsapp_outbox WHERE id=?', [$jobId]);
        return ['job_id' => $jobId, 'kind' => $job['kind'], 'status' => $job['status'], 'progress' => (int)$job['progress'], 'attempts' => (int)$job['attempts'], 'error' => $job['last_error']];
    }
}
