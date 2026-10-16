<?php
declare(strict_types=1);
namespace App;

/**
 * Solicitud de historia clínica conversando con el bot (en lugar del formulario web).
 *
 * El bot pide el número de documento y busca los datos de la última solicitud (patient_profiles). Si es la primera
 * vez, envía el enlace del formulario (allí quedan guardados). Si ya existen, le pide confirmarlos —desde otro número
 * primero debe escribir el nombre completo del paciente, como en el formulario— y luego pregunta fecha y especialidad.
 * La solicitud se radica con la misma búsqueda directa del formulario y queda vinculada a este chat.
 * El paso de la conversación se guarda en whatsapp_sessions con estados intake_*.
 */
final class WhatsAppIntake
{
    private const NAME_TRIES = 2;

    public function __construct(private readonly Application $app) {}

    /** El paciente pidió su historia (opción 1 o HISTORIA): se le pide el número de documento. */
    public function start(string $phone, string $messageId): void
    {
        $this->saveSession($phone, 'intake_document', []);
        $this->reply($phone, "Con gusto te ayudamos con tu historia clínica.\n\nEscribe el *número de documento* del paciente, sin puntos ni espacios.\n\nPara cancelar escribe *MENU*.", $messageId, 'document');
    }

    /** Siguiente paso de la conversación. false: se canceló y el bot normal responde (menú). */
    public function step(string $phone, string $messageId, string $text, string $state, array $data): bool
    {
        $text = trim($text);
        $words = preg_split('/[^a-z0-9]+/', strtolower(ClinicalPdfReader::normalize($text)), -1, PREG_SPLIT_NO_EMPTY);
        if (array_intersect($words, ['menu', 'cancelar', 'salir'])) { $this->endSession($phone); return false; }
        $profile = isset($data['profile_id']) ? $this->app->db->one('SELECT * FROM patient_profiles WHERE id=?', [$data['profile_id']]) : null;
        if ($state !== 'intake_document' && str_starts_with($state, 'intake_') && !$profile && !isset($data['request_id'])) $state = 'expired';
        match ($state) {
            'intake_document' => $this->document($phone, $messageId, $text),
            'intake_name' => $this->name($phone, $messageId, $text, $profile, $data),
            'intake_confirm' => $this->confirmData($phone, $messageId, $words, $profile),
            'intake_date' => $this->date($phone, $messageId, $text, $words, $profile),
            'intake_specialty' => $this->specialty($phone, $messageId, $text, $profile, $data),
            'intake_select' => $this->select($phone, $messageId, $text, $data),
            'intake_suggest' => $this->suggestion($phone, $messageId, $words, $data),
            default => $this->restart($phone, $messageId),
        };
        return true;
    }

    private function document(string $phone, string $messageId, string $text): void
    {
        $number = strtoupper(preg_replace('/[\s.\-]/', '', $text));
        if (!preg_match('/^[A-Z0-9]{3,24}$/', $number) || !preg_match('/\d/', $number)) {
            $this->reply($phone, 'Escribe solo el número de documento, sin puntos ni espacios (o *MENU* para cancelar).', $messageId, 'document-retry');
            return;
        }
        // Límite de consultas por chat: evita recorrer documentos ajenos.
        try { $this->app->limit('whatsapp-document:' . $phone, 10, 3600); }
        catch (\DomainException) {
            $this->endSession($phone);
            $this->reply($phone, 'Has hecho muchas consultas seguidas. Inténtalo de nuevo más tarde.', $messageId, 'document-limit');
            return;
        }
        $profile = $this->app->db->one('SELECT * FROM patient_profiles WHERE document_number=? ORDER BY updated_at DESC LIMIT 1', [$number]);
        if (!$profile) {
            // Primera vez del documento: solo el mensaje del formulario con su enlace.
            $this->endSession($phone);
            $this->sendFormLink($phone, $messageId);
            return;
        }
        if ($profile['whatsapp_phone'] === $phone) { $this->showData($phone, $messageId, $profile, false); return; }
        // Desde otro número: se exige el nombre completo, igual que en el formulario, antes de mostrar los datos.
        $this->saveSession($phone, 'intake_name', ['profile_id' => (int)$profile['id'], 'tries' => 0]);
        $this->reply($phone, 'Para proteger los datos del paciente, escribe su *nombre completo* (nombres y apellidos) tal como aparece en el documento.', $messageId, 'name');
    }

    private function name(string $phone, string $messageId, string $text, array $profile, array $data): void
    {
        if (Application::nameKey($text) === Application::nameKey($profile['full_name'])) { $this->showData($phone, $messageId, $profile, true); return; }
        $tries = (int)($data['tries'] ?? 0) + 1;
        if ($tries >= self::NAME_TRIES) {
            $this->endSession($phone);
            $this->reply($phone, 'El nombre no coincide con el de la última solicitud de ese documento. Completa tus datos en el formulario:', $messageId, 'name-fail');
            $this->sendFormLink($phone, $messageId);
            return;
        }
        $this->saveSession($phone, 'intake_name', ['profile_id' => (int)$profile['id'], 'tries' => $tries]);
        $this->reply($phone, 'El nombre no coincide. Escríbelo completo, con los dos apellidos, tal como aparece en el documento.', $messageId, 'name-retry');
    }

    private function showData(string $phone, string $messageId, array $profile, bool $mask): void
    {
        $this->saveSession($phone, 'intake_confirm', ['profile_id' => (int)$profile['id']]);
        $email = $mask ? self::maskEmail($profile['email']) : $profile['email'];
        $this->reply($phone, "Encontramos los datos de tu última solicitud:\n\n*Paciente:* {$profile['full_name']}\n*Documento:* {$profile['document_type']} {$profile['document_number']}\n*Correo:* $email\n\n¿Están correctos? Responde con el número:\n\n*1.* Sí, son correctos\n*2.* No, quiero corregirlos", $messageId, 'confirm');
    }

    private function confirmData(string $phone, string $messageId, array $words, array $profile): void
    {
        $answer = self::yesNo($words);
        if ($answer === null) { $this->reply($phone, 'Responde *1* si los datos están correctos o *2* para corregirlos.', $messageId, 'confirm-retry'); return; }
        if (!$answer) {
            $this->endSession($phone);
            $this->reply($phone, 'Corrige tus datos en el formulario. Quedarán guardados para tus próximas solicitudes por este chat:', $messageId, 'correct');
            $this->sendFormLink($phone, $messageId);
            return;
        }
        $this->saveSession($phone, 'intake_date', ['profile_id' => (int)$profile['id']]);
        $this->reply($phone, "¿En qué fecha fue la atención? Por ejemplo: *15/03/2026* o *10 de julio de 2026*.\n\nSi no la recuerdas, responde *0* y buscaremos la más reciente.", $messageId, 'date');
    }

    private function date(string $phone, string $messageId, string $text, array $words, array $profile): void
    {
        $date = $text === '0' || ($words && $words[0] === 'no') ? null : self::parseDate($text);
        if ($date === false) { $this->reply($phone, 'Escribe una fecha válida que no sea futura, por ejemplo *15/03/2026* o *10 de julio de 2026*, o *0* si no la recuerdas.', $messageId, 'date-retry'); return; }
        $specialties = $this->app->db->all('SELECT id,name FROM specialties WHERE active=1 ORDER BY name');
        $this->saveSession($phone, 'intake_specialty', ['profile_id' => (int)$profile['id'], 'date' => $date, 'ids' => array_map(fn($s) => (int)$s['id'], $specialties)]);
        $lines = ['*0.* No recuerdo la especialidad'];
        foreach ($specialties as $index => $specialty) $lines[] = '*' . ($index + 1) . '.* ' . $specialty['name'];
        $this->reply($phone, "¿De qué especialidad fue la atención? Responde con el número:\n\n" . implode("\n", $lines), $messageId, 'specialty');
    }

    private function specialty(string $phone, string $messageId, string $text, array $profile, array $data): void
    {
        $choice = ctype_digit($text) ? (int)$text : -1;
        if ($choice !== 0 && !isset($data['ids'][$choice - 1])) { $this->reply($phone, 'Responde solo con el número de la especialidad (o *0* si no la recuerdas).', $messageId, 'specialty-retry'); return; }
        $this->endSession($phone);
        try {
            $id = $this->app->createRequest(['document_type' => $profile['document_type'], 'document_number' => $profile['document_number'],
                'full_name' => $profile['full_name'], 'email' => $profile['email'], 'approximate_date' => $data['date'] ?? '',
                'date_unknown' => $data['date'] === null ? '1' : '', 'specialty_id' => $choice === 0 ? 0 : $data['ids'][$choice - 1]], 'whatsapp:' . $phone, true, null, $phone);
        } catch (\DomainException $error) {
            $this->reply($phone, 'No pudimos radicar tu solicitud: ' . $error->getMessage(), $messageId, 'create-fail');
            return;
        }
        $this->app->event($id, 'whatsapp_intake', 'created');
        $this->afterSearch($phone, $messageId, $id);
    }

    /**
     * Resultado de la búsqueda: si la historia o el radicado ya quedaron en cola, ese mensaje es la respuesta.
     * Varias atenciones o una sugerencia se resuelven aquí mismo en lugar de la página.
     */
    private function afterSearch(string $phone, string $messageId, int $id): void
    {
        $request = $this->app->request($id);
        if ($request['status'] === 'awaiting_selection') {
            $matches = $this->app->search($id);
            $this->saveSession($phone, 'intake_select', ['request_id' => $id, 'ids' => array_map(fn($m) => (int)$m['id'], $matches)]);
            $lines = [];
            foreach ($matches as $index => $match) $lines[] = '*' . ($index + 1) . '.* ' . date('d/m/Y', strtotime($match['attended_at'])) . ' · ' . $match['specialty'];
            $this->reply($phone, "Encontramos varias atenciones (radicado *{$request['reference']}*). ¿Cuál necesitas? Responde con el número:\n\n" . implode("\n", $lines), $messageId, 'select');
            return;
        }
        $suggestion = $request['status'] === 'no_matches' && !$this->noticeQueued($id) ? $this->app->recentSuggestion($id) : null;
        if ($suggestion) {
            $this->saveSession($phone, 'intake_suggest', ['request_id' => $id, 'encounter_id' => (int)$suggestion['id']]);
            $intro = $request['approximate_date'] === null ? 'La atención más reciente que tenemos' : 'No encontramos una atención en esa fecha. La más reciente que tenemos';
            $this->reply($phone, "$intro es del *" . date('d/m/Y', strtotime($suggestion['attended_at'])) . '* (' . $suggestion['specialty'] . ").\n\n¿Quieres que te enviemos esa historia?\n\n*1.* Sí, enviármela\n*2.* No, es otra atención", $messageId, 'suggest');
        }
    }

    private function select(string $phone, string $messageId, string $text, array $data): void
    {
        $choice = ctype_digit($text) ? (int)$text : 0;
        if (!isset($data['ids'][$choice - 1])) { $this->reply($phone, 'Responde solo con el número de la atención.', $messageId, 'select-retry'); return; }
        $this->endSession($phone);
        try { $this->app->confirm((int)$data['request_id'], $data['ids'][$choice - 1]); }
        catch (\DomainException) { $this->reply($phone, 'Esa atención ya no está disponible. Escribe *HISTORIA* para hacer una nueva solicitud.', $messageId, 'select-fail'); }
    }

    private function suggestion(string $phone, string $messageId, array $words, array $data): void
    {
        $answer = self::yesNo($words);
        if ($answer === null) { $this->reply($phone, 'Responde *1* para recibir esa historia o *2* si es otra atención.', $messageId, 'suggest-retry'); return; }
        $this->endSession($phone);
        $id = (int)$data['request_id'];
        if ($answer) {
            try { $this->app->selectSuggestion($id, (int)$data['encounter_id']); return; }
            catch (\DomainException) {}
        }
        // Sin historia elegida: queda para gestión y recibe el radicado.
        $this->app->event($id, 'whatsapp_intake', $answer ? 'suggestion_unavailable' : 'suggestion_rejected');
        $this->app->whatsapp()->queueNotFound($id);
    }

    private function restart(string $phone, string $messageId): void
    {
        $this->endSession($phone);
        $this->reply($phone, 'La conversación venció. Escribe *HISTORIA* para empezar de nuevo.', $messageId, 'expired');
    }

    private function noticeQueued(int $requestId): bool
    {
        return (bool)$this->app->db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$requestId]);
    }

    private function sendFormLink(string $phone, string $messageId): void
    {
        $link = $this->app->whatsapp()->createFormLink($phone);
        $this->reply($phone, (new MessageTemplates($this->app))->render('form_link', ['enlace' => $link['url'], 'vigencia' => $link['hours']]), $messageId, 'link');
    }

    /** true (1, sí), false (2, no) o null si no se entiende. */
    private static function yesNo(array $words): ?bool
    {
        $first = $words[0] ?? '';
        if (in_array($first, ['1', 'si', 'correcto', 'correctos', 'ok', 'claro'], true)) return true;
        if (in_array($first, ['2', 'no'], true)) return false;
        return null;
    }

    private const MONTHS = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    /**
     * Fecha en formato Y-m-d; false si no es válida o es futura. Acepta día/mes/año, año-mes-día y el mes escrito
     * («10 de julio del 2026», «10 jul 2026», «julio 10 de 2026»). Sin año se toma el último 10 de julio ya pasado.
     */
    public static function parseDate(string $text): string|false
    {
        $text = trim($text);
        $words = trim(preg_replace(['/[,.]/', '/\s+/'], [' ', ' '], strtolower(ClinicalPdfReader::normalize($text))));
        $words = preg_replace('/^(el|dia)\s+/', '', $words);
        $month = '([a-z]{3,10})';
        $year = null;
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $text, $m)) [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})[\/\-. ](\d{1,2})[\/\-. ](\d{2}|\d{4})$/', $text, $m)) [$day, $month, $year] = [(int)$m[1], (int)$m[2], strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})\s*(?:de\s+)?' . $month . '(?:\s+(?:de\s+|del\s+)?(\d{4}))?$/', $words, $m)
            || preg_match('/^' . $month . '\s+(\d{1,2})(?:\s+(?:de\s+|del\s+)?(\d{4}))?$/', $words, $n)) {
            if (!$m) $m = [$n[0], $n[2], $n[1], $n[3] ?? ''];
            $key = substr($m[2], 0, 3);
            if (!isset(self::MONTHS[$key]) || (strlen($m[2]) > 3 && !str_starts_with(self::monthName($key), $m[2]) && $m[2] !== 'setiembre')) return false;
            [$day, $month] = [(int)$m[1], self::MONTHS[$key]];
            $year = ($m[3] ?? '') !== '' ? (int)$m[3] : null;
            if ($year === null) {
                $year = (int)date('Y');
                if (sprintf('%04d-%02d-%02d', $year, $month, $day) > date('Y-m-d')) $year--;
            }
        }
        else return false;
        if (!checkdate($month, $day, $year)) return false;
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        return $date > date('Y-m-d') ? false : $date;
    }

    private static function monthName(string $key): string
    {
        return ['ene' => 'enero', 'feb' => 'febrero', 'mar' => 'marzo', 'abr' => 'abril', 'may' => 'mayo', 'jun' => 'junio', 'jul' => 'julio',
            'ago' => 'agosto', 'sep' => 'septiembre', 'set' => 'septiembre', 'oct' => 'octubre', 'nov' => 'noviembre', 'dic' => 'diciembre'][$key];
    }

    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($user, 0, 2) . str_repeat('*', max(3, mb_strlen($user) - 2)) . '@' . $domain;
    }

    private function reply(string $phone, string $text, string $messageId, string $suffix): void
    {
        $this->app->whatsapp()->queueText($phone, $text, 'intake:' . $messageId . ':' . $suffix);
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
}
