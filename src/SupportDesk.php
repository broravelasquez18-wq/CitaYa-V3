<?php
declare(strict_types=1);
namespace App;

/**
 * Reporte de errores de la historia clínica por WhatsApp y chat con el paciente (SCRUM-47/48).
 *
 * El bot guía el reporte (documento del paciente, qué historia, qué problema, descripción). El documento valida a la persona:
 * solo se listan las historias enviadas a este WhatsApp para ese documento. «No me llegó / no abre» lo resuelve solo
 * reenviando la historia; lo demás abre un caso para el empleado que respondió esa historia (o el de menor carga)
 * y se le avisa en el panel (contador y aviso en pantalla). Mientras el caso está abierto, lo que escribe el paciente llega al chat del caso en el sistema y
 * el personal le contesta por el mismo número del bot. Los textos de los mensajes se guardan cifrados.
 */
final class SupportDesk
{
    /** opción del menú => [código, etiqueta, requiere empleado] */
    public const CATEGORIES = [
        '1' => ['not_received', 'No me llegó o no puedo abrir el archivo', false],
        '2' => ['wrong_data', 'Mis datos personales están mal', true],
        '3' => ['missing', 'Falta información o un documento', true],
        '4' => ['wrong_visit', 'Me enviaron una atención que no es', true],
        '5' => ['other', 'Otro problema', true],
    ];
    private const SESSION_MINUTES = 30;
    private const DOCUMENT_TRIES = 3;
    private const DELIVERED = ['sent', 'simulated', 'queued', 'sending', 'retry', 'uncertain', 'failed'];

    public function __construct(private readonly Application $app) {}

    /**
     * Procesa un mensaje entrante si le corresponde (caso abierto, reporte en curso u opción «2»).
     * Devuelve true si lo atendió; false para que siga el bot normal (menú, HISTORIA, CONFIRMAR…).
     */
    public function handle(string $phone, string $messageId, string $text): bool
    {
        $phone = preg_replace('/\D/', '', $phone);
        $text = trim($text);
        if ($phone === '') return false;
        $ticket = $this->app->db->one("SELECT * FROM support_tickets WHERE phone=? AND status='open' ORDER BY id DESC LIMIT 1", [$phone]);
        if ($ticket) {
            if ($text === '') return true;
            $this->addMessage((int)$ticket['id'], 'in', $text, null, $messageId);
            $this->app->db->run('UPDATE support_tickets SET unread=unread+1,updated_at=? WHERE id=?', [$this->app->now(), $ticket['id']]);
            return true;
        }
        $session = $this->app->db->one('SELECT * FROM whatsapp_sessions WHERE phone=? AND updated_at>?', [$phone, date('Y-m-d H:i:s', time() - 60 * self::SESSION_MINUTES)]);
        // Solicitud de historia conversando con el bot (WhatsAppIntake): tiene sus propias opciones y su cancelación.
        if ($session && str_starts_with($session['state'], 'intake_')) {
            return (new WhatsAppIntake($this->app))->step($phone, $messageId, $text, $session['state'], json_decode((string)$session['data'], true) ?: []);
        }
        $words = self::words($text);
        if ($session && (in_array('cancelar', $words, true) || in_array('menu', $words, true) || $text === '0')) {
            $this->endSession($phone);
            $this->reply($phone, 'Reporte cancelado.', $messageId, 'cancel');
            return false; // El bot normal muestra el menú.
        }
        if ($session) return $this->step($phone, $messageId, $text, $session['state'], json_decode((string)$session['data'], true) ?: []);
        if ($text === '2' || array_intersect($words, ['reportar', 'reporte', 'error', 'errores', 'fallo', 'problema'])) {
            $this->start($phone, $messageId);
            return true;
        }
        return false;
    }

    private function start(string $phone, string $messageId): void
    {
        $this->saveSession($phone, 'ask_document', ['tries' => 0]);
        $this->reply($phone, "Para validar tu identidad, escribe el *número de documento* del paciente, sin puntos ni espacios.\n\n*0.* Cancelar", $messageId, 'document');
    }

    /** Valida el documento contra las historias enviadas a este WhatsApp antes de mostrar cualquiera. */
    private function document(string $phone, string $messageId, string $text, array $data): void
    {
        $number = strtoupper(preg_replace('/[\s.\-]/', '', $text));
        if (!preg_match('/^[A-Z0-9]{3,24}$/', $number) || !preg_match('/\d/', $number)) {
            $this->reply($phone, 'Escribe solo el número de documento, sin puntos ni espacios (*0* para cancelar).', $messageId, 'document-retry');
            return;
        }
        // Límite por chat: evita probar documentos ajenos.
        try { $this->app->limit('support-document:' . $phone, 10, 3600); }
        catch (\DomainException) {
            $this->endSession($phone);
            $this->reply($phone, 'Demasiados intentos. Inténtalo más tarde.', $messageId, 'document-limit');
            return;
        }
        $requests = $this->deliveredRequests($phone, $number);
        if (!$requests) {
            $tries = (int)($data['tries'] ?? 0) + 1;
            if ($tries >= self::DOCUMENT_TRIES) {
                $this->endSession($phone);
                $this->reply($phone, 'No pudimos validar el documento con las historias enviadas a este WhatsApp. Si necesitas una historia, responde *1*.', $messageId, 'document-failed');
                return;
            }
            $this->saveSession($phone, 'ask_document', ['tries' => $tries]);
            $this->reply($phone, 'Ese documento no tiene historias enviadas a este WhatsApp. Revísalo y escríbelo de nuevo (*0* para cancelar).', $messageId, 'document-mismatch');
            return;
        }
        $this->chooseRequest($phone, $messageId, $requests);
    }

    private function chooseRequest(string $phone, string $messageId, array $requests): void
    {
        if (count($requests) === 1) { $this->askCategory($phone, $messageId, (int)$requests[0]['id']); return; }
        $lines = [];
        foreach ($requests as $index => $request) $lines[] = '*' . ($index + 1) . '.* Solicitada el ' . self::longDate($request['created_at']);
        $this->saveSession($phone, 'choose_request', ['ids' => array_map(fn($r) => (int)$r['id'], $requests)]);
        $this->reply($phone, "¿Sobre cuál historia es el reporte?\n\n" . implode("\n", $lines), $messageId, 'choose');
    }

    private function step(string $phone, string $messageId, string $text, string $state, array $data): bool
    {
        if ($state === 'ask_document') {
            $this->document($phone, $messageId, $text, $data);
            return true;
        }
        if ($state === 'choose_request') {
            $choice = (int)$text;
            if ($choice < 1 || !isset($data['ids'][$choice - 1])) { $this->reply($phone, 'Responde con el número de la historia (*0* para cancelar).', $messageId, 'retry'); return true; }
            $this->askCategory($phone, $messageId, (int)$data['ids'][$choice - 1]);
            return true;
        }
        if ($state === 'choose_category') {
            if (!isset(self::CATEGORIES[$text])) { $this->reply($phone, 'Responde un número del *1* al *5* (*0* para cancelar).', $messageId, 'retry'); return true; }
            [$category, , $needsStaff] = self::CATEGORIES[$text];
            $requestId = (int)$data['request_id'];
            if (!$needsStaff) {
                // Lo resuelve el bot: reenvía la historia por WhatsApp.
                $resent = $this->app->whatsapp()->resendDelivery($requestId);
                $ticket = $this->createTicket($phone, $requestId, $category, null, $resent ? 'auto_resolved' : 'open');
                $this->endSession($phone);
                if ($resent) {
                    $this->reply($phone, "Listo ✅ Te reenviamos tu historia por este chat.", $messageId, 'resent');
                } else {
                    $this->reply($phone, "No pudimos reenviarla. Tu reporte es *{$ticket['reference']}*; te escribiremos por aquí.", $messageId, 'resend-failed');
                }
                return true;
            }
            $this->saveSession($phone, 'describe', ['request_id' => $requestId, 'category' => $category]);
            $this->reply($phone, 'Cuéntanos en un mensaje cuál es el error.', $messageId, 'describe');
            return true;
        }
        if ($state === 'describe') {
            if (mb_strlen($text) < 3) { $this->reply($phone, 'Escribe cuál es el error.', $messageId, 'retry'); return true; }
            $ticket = $this->createTicket($phone, (int)$data['request_id'], (string)$data['category'], mb_substr($text, 0, 2000), 'open');
            $this->addMessage((int)$ticket['id'], 'in', $text, null, $messageId);
            $this->endSession($phone);
            $this->reply($phone, "Reporte *{$ticket['reference']}* recibido 📝 Te responderemos por este chat.", $messageId, 'created');
            return true;
        }
        $this->endSession($phone);
        return false;
    }

    private function askCategory(string $phone, string $messageId, int $requestId): void
    {
        $options = [];
        foreach (self::CATEGORIES as $key => [, $label]) $options[] = '*' . $key . '.* ' . $label;
        $this->saveSession($phone, 'choose_category', ['request_id' => $requestId]);
        $this->reply($phone, "¿Qué problema tiene tu historia?\n\n" . implode("\n", $options) . "\n\n*0.* Cancelar", $messageId, 'category');
    }

    /** Historias de ese documento enviadas (o respondidas como Aprobado) a este WhatsApp, de la más reciente a la más antigua. */
    public function deliveredRequests(string $phone, string $document): array
    {
        return $this->app->db->all("SELECT id,reference,created_at FROM requests WHERE whatsapp_phone=? AND claimed_document_number=?
            AND (status IN ('" . implode("','", self::DELIVERED) . "') OR resolution='approved') ORDER BY id DESC LIMIT 5", [$phone, $document]);
    }

    /** Crea el caso y lo asigna al empleado que respondió la historia o, si no hubo, al de menor carga. */
    private function createTicket(string $phone, int $requestId, string $category, ?string $description, string $status): array
    {
        $request = $this->app->request($requestId);
        $assigned = null;
        if ($status === 'open') {
            if ($request['resolved_by'] && $this->app->db->one('SELECT id FROM admins WHERE id=? AND active=1', [$request['resolved_by']])) $assigned = (int)$request['resolved_by'];
            else $assigned = $this->leastLoaded();
        }
        $id = $this->app->db->insert('support_tickets', [
            'reference' => 'RE-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), 'phone' => $phone, 'request_id' => $requestId,
            'category' => $category, 'description' => $description === null ? null : Crypto::seal($description, $this->app->config['app_key']),
            'status' => $status, 'assigned_to' => $assigned, 'created_at' => $this->app->now(), 'updated_at' => $this->app->now(),
            'closed_at' => $status === 'auto_resolved' ? $this->app->now() : null,
        ]);
        $this->app->event($requestId, 'support_ticket', $category . ':' . $status);
        return $this->app->db->one('SELECT * FROM support_tickets WHERE id=?', [$id]);
    }

    /** Empleado activo con menos casos abiertos; si no hay empleados, un administrador. */
    private function leastLoaded(): ?int
    {
        $row = $this->app->db->one("SELECT a.id FROM admins a WHERE a.active=1 ORDER BY a.role='employee' DESC,
            (SELECT COUNT(*) FROM support_tickets t WHERE t.assigned_to=a.id AND t.status='open'), a.id LIMIT 1");
        return $row ? (int)$row['id'] : null;
    }

    // --- Panel: lista, chat y cierre ---

    /** Casos para el panel. El empleado ve los suyos; el administrador, todos. */
    public function tickets(array $staff, string $filter = 'open'): array
    {
        $where = $filter === 'closed' ? "t.status IN ('closed','auto_resolved')" : "t.status='open'";
        $params = [];
        if ($staff['role'] !== 'admin') { $where .= ' AND t.assigned_to=?'; $params[] = $staff['id']; }
        $rows = $this->app->db->all("SELECT t.*,r.reference request_reference,r.claimed_name,a.name assigned_name FROM support_tickets t
            LEFT JOIN requests r ON r.id=t.request_id LEFT JOIN admins a ON a.id=t.assigned_to WHERE $where ORDER BY t.unread>0 DESC, t.updated_at DESC LIMIT 100", $params);
        foreach ($rows as &$row) $row['category_label'] = $this->categoryLabel($row['category']);
        return $rows;
    }

    /**
     * Avisos del panel: reportes abiertos que esperan respuesta (nuevos o con mensajes del paciente sin leer).
     * Lo consulta el panel cada 15 s para actualizar el contador y mostrar el aviso en pantalla.
     */
    public function notifications(array $staff): array
    {
        $sql = "SELECT t.id,t.reference,t.category,t.unread,t.updated_at FROM support_tickets t WHERE t.status='open'
            AND (t.unread>0 OR NOT EXISTS (SELECT 1 FROM support_messages m WHERE m.ticket_id=t.id AND m.direction='out'))";
        $rows = $staff['role'] === 'admin' ? $this->app->db->all($sql . ' ORDER BY t.updated_at DESC LIMIT 20')
            : $this->app->db->all($sql . ' AND t.assigned_to=? ORDER BY t.updated_at DESC LIMIT 20', [$staff['id']]);
        return array_map(fn($row) => ['id' => (int)$row['id'], 'reference' => $row['reference'], 'label' => $this->categoryLabel($row['category']),
            'unread' => (int)$row['unread'], 'updated_at' => $row['updated_at']], $rows);
    }

    public function unreadCount(array $staff): int
    {
        $sql = "SELECT COUNT(*) n FROM support_tickets WHERE status='open' AND (unread>0 OR NOT EXISTS (SELECT 1 FROM support_messages m WHERE m.ticket_id=support_tickets.id AND m.direction='out'))";
        return (int)($staff['role'] === 'admin' ? $this->app->db->one($sql)['n'] : $this->app->db->one($sql . ' AND assigned_to=?', [$staff['id']])['n']);
    }

    /** Caso con sus mensajes (descifrados). Marca como leído si lo abre la persona asignada. */
    public function ticket(int $id, array $staff, bool $markRead = true): ?array
    {
        $ticket = $this->app->db->one('SELECT t.*,r.reference request_reference,r.claimed_name,r.contact_email,a.name assigned_name FROM support_tickets t
            LEFT JOIN requests r ON r.id=t.request_id LEFT JOIN admins a ON a.id=t.assigned_to WHERE t.id=?', [$id]);
        if (!$ticket || !$this->canSee($ticket, $staff)) return null;
        $key = $this->app->config['app_key'];
        $ticket['description'] = $ticket['description'] ? Crypto::open($ticket['description'], $key) : null;
        $ticket['category_label'] = $this->categoryLabel($ticket['category']);
        $ticket['messages'] = array_map(function ($message) use ($key) {
            $message['body'] = Crypto::open($message['body'], $key);
            return $message;
        }, $this->app->db->all('SELECT m.*,a.name author FROM support_messages m LEFT JOIN admins a ON a.id=m.admin_id WHERE m.ticket_id=? ORDER BY m.id', [$id]));
        if ($markRead && (int)$ticket['assigned_to'] === (int)$staff['id'] && $ticket['unread'] > 0) $this->app->db->run('UPDATE support_tickets SET unread=0 WHERE id=?', [$id]);
        return $ticket;
    }

    /** El personal responde en el chat: el mensaje sale por el número del bot. */
    public function send(int $id, array $staff, string $body): void
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > 2000) throw new \DomainException('Escribe un mensaje (máximo 2000 caracteres).');
        $ticket = $this->openTicket($id, $staff);
        $messageId = $this->addMessage($id, 'out', $body, (int)$staff['id']);
        $this->app->whatsapp()->queueText($ticket['phone'], $body, 'support-out:' . $messageId);
        $this->app->db->run('UPDATE support_tickets SET updated_at=?,unread=0,assigned_to=COALESCE(assigned_to,?) WHERE id=?', [$this->app->now(), $staff['id'], $id]);
    }

    public function close(int $id, array $staff): void
    {
        $ticket = $this->openTicket($id, $staff);
        $this->app->db->run("UPDATE support_tickets SET status='closed',closed_at=?,closed_by=?,unread=0,updated_at=? WHERE id=?", [$this->app->now(), $staff['id'], $this->app->now(), $id]);
        $text = "Tu reporte *{$ticket['reference']}* quedó resuelto ✅ Si necesitas algo más, escribe *MENU*.";
        $this->addMessage($id, 'system', $text, (int)$staff['id']);
        $this->app->whatsapp()->queueText($ticket['phone'], $text, 'support-close:' . $id);
        $this->app->event($ticket['request_id'] ? (int)$ticket['request_id'] : null, 'support_ticket', 'closed:admin_' . $staff['id']);
    }

    public function categoryLabel(string $category): string
    {
        foreach (self::CATEGORIES as [$code, $label]) if ($code === $category) return $label;
        return $category;
    }

    private function openTicket(int $id, array $staff): array
    {
        $ticket = $this->app->db->one('SELECT * FROM support_tickets WHERE id=?', [$id]);
        if (!$ticket || !$this->canSee($ticket, $staff)) throw new \DomainException('El reporte no existe o no está asignado a tu cuenta.');
        if ($ticket['status'] !== 'open') throw new \DomainException('El reporte ya está cerrado.');
        return $ticket;
    }

    private function canSee(array $ticket, array $staff): bool
    {
        return $staff['role'] === 'admin' || (int)$ticket['assigned_to'] === (int)$staff['id'];
    }

    private function addMessage(int $ticketId, string $direction, string $body, ?int $adminId, ?string $messageId = null): int
    {
        return $this->app->db->insert('support_messages', ['ticket_id' => $ticketId, 'direction' => $direction, 'admin_id' => $adminId,
            'body' => Crypto::seal($body, $this->app->config['app_key']), 'wa_message_id' => $messageId ? mb_substr($messageId, 0, 190) : null, 'created_at' => $this->app->now()]);
    }

    private function reply(string $phone, string $text, string $messageId, string $suffix): void
    {
        $this->app->whatsapp()->queueText($phone, $text, 'support:' . $messageId . ':' . $suffix);
    }

    private function saveSession(string $phone, string $state, array $data): void
    {
        $this->app->db->run('INSERT INTO whatsapp_sessions (phone,state,data,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),data=VALUES(data),updated_at=VALUES(updated_at)',
            [$phone, $state, json_encode($data), $this->app->now()]);
    }

    private function endSession(string $phone): void
    {
        $this->app->db->run('DELETE FROM whatsapp_sessions WHERE phone=?', [$phone]);
    }

    /** «viernes 16 de octubre de 2026, 3:42 p. m.»: el paciente distingue sus historias sin ver el radicado. */
    private static function longDate(string $value): string
    {
        $time = strtotime($value);
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $days[(int)date('w', $time)] . ' ' . date('j', $time) . ' de ' . $months[(int)date('n', $time) - 1] . ' de ' . date('Y', $time)
            . ', ' . date('g:i', $time) . (date('A', $time) === 'AM' ? ' a. m.' : ' p. m.');
    }

    private static function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower(ClinicalPdfReader::normalize($text)), -1, PREG_SPLIT_NO_EMPTY);
    }
}
