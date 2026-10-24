<?php
declare(strict_types=1);
namespace App;

/**
 * Solicitud de historia clínica conversando con el bot (en lugar del formulario web).
 *
 * El bot pide el número de documento y busca los datos de la última solicitud (patient_profiles). Si es la primera
 * vez, envía el enlace del formulario (allí quedan guardados). Si ya existen, le pide confirmarlos —desde un WhatsApp que
 * aún no conoce al paciente, primero debe escribir el nombre completo, una sola vez— y luego pregunta fecha y especialidad.
 * La solicitud se radica con la misma búsqueda directa del formulario y queda vinculada a este chat.
 * Al terminar, el bot ofrece pedir otra historia del mismo paciente (solo fecha y especialidad). Como en la web, el
 * documento puede tener como máximo Application::REQUESTS_PER_WINDOW solicitudes esperando respuesta.
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
        $this->reply($phone, "Escribe el *número de documento* del paciente, sin puntos.\n\n(*MENU* para cancelar)", $messageId, 'document');
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
            'intake_another' => $this->another($phone, $messageId, $words, $profile),
            default => $this->restart($phone, $messageId),
        };
        return true;
    }

    private function document(string $phone, string $messageId, string $text): void
    {
        $number = strtoupper(preg_replace('/[\s.\-]/', '', $text));
        if (!preg_match('/^[A-Z0-9]{3,24}$/', $number) || !preg_match('/\d/', $number)) {
            $this->reply($phone, 'Escribe solo el número, sin puntos ni espacios.', $messageId, 'document-retry');
            return;
        }
        // Límite de consultas por chat: evita recorrer documentos ajenos.
        try { $this->app->limit('whatsapp-document:' . $phone, 10, 3600); }
        catch (\DomainException) {
            $this->endSession($phone);
            $this->reply($phone, 'Demasiados intentos. Inténtalo más tarde.', $messageId, 'document-limit');
            return;
        }
        $profile = $this->app->db->one('SELECT * FROM patient_profiles WHERE document_number=? ORDER BY updated_at DESC LIMIT 1', [$number]);
        if (!$profile) {
            // Primera vez del documento: solo el mensaje del formulario con su enlace.
            $this->endSession($phone);
            $this->sendFormLink($phone, $messageId);
            return;
        }
        if ($this->app->isKnownProfileChat($profile, $phone)) { $this->showData($phone, $messageId, $profile, false); return; }
        // Desde un WhatsApp que aún no conoce al paciente: se exige el nombre completo (una sola vez) antes de mostrar los datos.
        $this->saveSession($phone, 'intake_name', ['profile_id' => (int)$profile['id'], 'tries' => 0]);
        $this->reply($phone, 'Escribe el *nombre completo* del paciente, como aparece en el documento.', $messageId, 'name');
    }

    private function name(string $phone, string $messageId, string $text, array $profile, array $data): void
    {
        if (Application::nameKey($text) === Application::nameKey($profile['full_name'])) {
            // Nombre correcto: este chat queda recordado y no se le vuelve a pedir.
            $this->app->rememberProfileChat((int)$profile['id'], $phone);
            $this->showData($phone, $messageId, $profile, true);
            return;
        }
        $tries = (int)($data['tries'] ?? 0) + 1;
        if ($tries >= self::NAME_TRIES) {
            $this->endSession($phone);
            $this->reply($phone, 'El nombre no coincide. Completa el formulario:', $messageId, 'name-fail');
            $this->sendFormLink($phone, $messageId);
            return;
        }
        $this->saveSession($phone, 'intake_name', ['profile_id' => (int)$profile['id'], 'tries' => $tries]);
        $this->reply($phone, 'El nombre no coincide. Escríbelo completo, con los dos apellidos.', $messageId, 'name-retry');
    }

    private function showData(string $phone, string $messageId, array $profile, bool $mask): void
    {
        $this->saveSession($phone, 'intake_confirm', ['profile_id' => (int)$profile['id']]);
        $email = $mask ? self::maskEmail($profile['email']) : $profile['email'];
        $this->reply($phone, "¿Tus datos son correctos?\n\n*Paciente:* {$profile['full_name']}\n*Documento:* {$profile['document_type']} {$profile['document_number']}\n*Correo:* $email\n\n*1.* Sí\n*2.* No, corregir", $messageId, 'confirm');
    }

    private function confirmData(string $phone, string $messageId, array $words, array $profile): void
    {
        $answer = self::yesNo($words);
        if ($answer === null) { $this->reply($phone, 'Responde *1* (sí) o *2* (no).', $messageId, 'confirm-retry'); return; }
        if (!$answer) {
            $this->endSession($phone);
            $this->reply($phone, 'Corrige tus datos aquí:', $messageId, 'correct');
            $this->sendFormLink($phone, $messageId);
            return;
        }
        $this->askDate($phone, $messageId, $profile);
    }

    /** Pide la fecha si el documento aún puede tener otra solicitud esperando respuesta; si no, explica hasta cuándo. */
    private function askDate(string $phone, string $messageId, array $profile): void
    {
        $limit = $this->limitMessage($profile);
        if ($limit !== null) { $this->endSession($phone); $this->reply($phone, $limit, $messageId, 'limit'); return; }
        $this->saveSession($phone, 'intake_date', ['profile_id' => (int)$profile['id']]);
        $this->reply($phone, "¿Qué fecha fue la atención? Ej.: *10 de julio de 2026*.\n\nSi no la recuerdas, responde *0*.", $messageId, 'date');
    }

    /** Mensaje de la regla de solicitudes sin respuesta, o null si el documento todavía puede pedir otra. */
    private function limitMessage(array $profile): ?string
    {
        try { $this->app->assertNoRecentRequest(['document_type' => $profile['document_type'], 'document_number' => $profile['document_number']], Application::REQUESTS_PER_WINDOW); }
        catch (\DomainException $error) { return $error->getMessage(); }
        return null;
    }

    /** Al terminar una solicitud: ofrece otra historia del mismo paciente si queda cupo. */
    private function offerAnother(string $phone, string $messageId, array $profile): void
    {
        if ($this->limitMessage($profile) !== null) { $this->endSession($phone); return; }
        $this->saveSession($phone, 'intake_another', ['profile_id' => (int)$profile['id']]);
        $this->reply($phone, "¿Necesitas otra historia clínica de *{$profile['full_name']}*?\n\n*1.* Sí\n*2.* No", $messageId, 'another');
    }

    /** Ofrece otra historia con el perfil guardado en la conversación (una sesión anterior a este cambio no lo trae). */
    private function offerFor(string $phone, string $messageId, array $data): void
    {
        $profile = isset($data['profile_id']) ? $this->app->db->one('SELECT * FROM patient_profiles WHERE id=?', [$data['profile_id']]) : null;
        if ($profile) $this->offerAnother($phone, $messageId, $profile);
    }

    private function another(string $phone, string $messageId, array $words, array $profile): void
    {
        $answer = self::yesNo($words);
        if ($answer === null) { $this->reply($phone, 'Responde *1* (sí) o *2* (no).', $messageId, 'another-retry'); return; }
        if (!$answer) { $this->endSession($phone); $this->reply($phone, 'Listo. Si necesitas algo más, escribe *MENU*.', $messageId, 'another-no'); return; }
        $this->askDate($phone, $messageId, $profile);
    }

    private function date(string $phone, string $messageId, string $text, array $words, array $profile): void
    {
        $date = $text === '0' || self::unknown($words) ? null : self::parseDate($text);
        if ($date === false) { $this->reply($phone, 'Fecha no válida. Ej.: *10 de julio de 2026*, o *0* si no la recuerdas.', $messageId, 'date-retry'); return; }
        $specialties = $this->app->db->all('SELECT id,name FROM specialties WHERE active=1 ORDER BY name');
        $this->saveSession($phone, 'intake_specialty', ['profile_id' => (int)$profile['id'], 'date' => $date, 'ids' => array_map(fn($s) => (int)$s['id'], $specialties)]);
        $lines = ['*0.* No recuerdo'];
        foreach ($specialties as $index => $specialty) $lines[] = '*' . ($index + 1) . '.* ' . $specialty['name'];
        $this->reply($phone, "¿Qué especialidad?\n\n" . implode("\n", $lines), $messageId, 'specialty');
    }

    private function specialty(string $phone, string $messageId, string $text, array $profile, array $data): void
    {
        $choice = $this->specialtyChoice($text, $data['ids']);
        if (is_array($choice)) {
            // Varias especialidades coinciden con lo escrito (p. ej. «medicina»): se pregunta solo entre ellas.
            $this->reply($phone, "¿Cuál de estas?\n\n" . implode("\n", $choice), $messageId, 'specialty-which');
            return;
        }
        if ($choice === null) { $this->reply($phone, 'No reconocimos esa especialidad. Responde con el número de la lista, o *0* si no la recuerdas.', $messageId, 'specialty-retry'); return; }
        $this->endSession($phone);
        try {
            $this->app->assertNoRecentRequest(['document_type' => $profile['document_type'], 'document_number' => $profile['document_number']], Application::REQUESTS_PER_WINDOW);
            $id = $this->app->createRequest(['document_type' => $profile['document_type'], 'document_number' => $profile['document_number'],
                'full_name' => $profile['full_name'], 'email' => $profile['email'], 'approximate_date' => $data['date'] ?? '',
                'date_unknown' => $data['date'] === null ? '1' : '', 'specialty_id' => $choice === 0 ? 0 : $data['ids'][$choice - 1]], 'whatsapp:' . $phone, true, null, $phone);
        } catch (\DomainException $error) {
            $this->reply($phone, 'No pudimos radicar: ' . $error->getMessage(), $messageId, 'create-fail');
            return;
        }
        $this->app->event($id, 'whatsapp_intake', 'created');
        $this->afterSearch($phone, $messageId, $id, $profile);
    }

    /**
     * Opción elegida en la lista de especialidades: el número, «no recuerdo» (0) o el nombre escrito («pediatría»,
     * «fue en ortopedia»). Devuelve la posición (0 = no recuerda), las líneas de las opciones si el texto coincide con
     * varias, o null si no se entiende.
     */
    private function specialtyChoice(string $text, array $ids): int|array|null
    {
        if (ctype_digit($text)) return (int)$text === 0 || isset($ids[(int)$text - 1]) ? (int)$text : null;
        $clean = self::plain($text);
        if ($clean === '') return null;
        if (self::unknown(explode(' ', $clean))) return 0;
        $names = [];
        foreach ($ids as $index => $id) {
            $row = $this->app->db->one('SELECT name FROM specialties WHERE id=?', [$id]);
            if ($row) $names[$index + 1] = $row['name'];
        }
        // Primero el nombre exacto; si no, los nombres mencionados en el texto; si no, los que contienen lo escrito.
        $found = array_keys(array_filter($names, fn($name) => self::plain($name) === $clean));
        if (!$found) $found = array_keys(array_filter($names, fn($name) => str_contains(' ' . $clean . ' ', ' ' . self::plain($name) . ' ')));
        if (!$found && mb_strlen($clean) >= 4) $found = array_keys(array_filter($names, fn($name) => str_contains(' ' . self::plain($name) . ' ', ' ' . $clean)));
        if (count($found) === 1) return $found[0];
        if (!$found) return null;
        return array_map(fn($position) => '*' . $position . '.* ' . $names[$position], $found);
    }

    /** Minúsculas, sin tildes ni signos y con espacios simples. */
    private static function plain(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(ClinicalPdfReader::normalize($text))));
    }

    /** El paciente dice que no recuerda («no recuerdo», «no sé», «ninguna»…). */
    private static function unknown(array $words): bool
    {
        $first = $words[0] ?? '';
        return $first === 'no' || in_array($first, ['nose', 'ninguna', 'ninguno', 'desconozco', 'olvide'], true);
    }

    /**
     * Resultado de la búsqueda: si la historia o el radicado ya quedaron en cola, ese mensaje es la respuesta.
     * Varias atenciones o una sugerencia se resuelven aquí mismo en lugar de la página.
     */
    private function afterSearch(string $phone, string $messageId, int $id, array $profile): void
    {
        $request = $this->app->request($id);
        if ($request['status'] === 'awaiting_selection') {
            $matches = $this->app->search($id);
            $this->saveSession($phone, 'intake_select', ['request_id' => $id, 'profile_id' => (int)$profile['id'], 'ids' => array_map(fn($m) => (int)$m['id'], $matches)]);
            $lines = [];
            foreach ($matches as $index => $match) $lines[] = '*' . ($index + 1) . '.* ' . date('d/m/Y', strtotime($match['attended_at'])) . ' · ' . $match['specialty'];
            $this->reply($phone, "Radicado *{$request['reference']}*. ¿Cuál atención necesitas?\n\n" . implode("\n", $lines), $messageId, 'select');
            return;
        }
        $suggestion = $request['status'] === 'no_matches' && !$this->noticeQueued($id) ? $this->app->recentSuggestion($id) : null;
        if ($suggestion) {
            $this->saveSession($phone, 'intake_suggest', ['request_id' => $id, 'profile_id' => (int)$profile['id'], 'encounter_id' => (int)$suggestion['id']]);
            $intro = $request['approximate_date'] === null ? 'La atención más reciente' : 'No hay atención en esa fecha. La más reciente';
            $this->reply($phone, "$intro es del *" . date('d/m/Y', strtotime($suggestion['attended_at'])) . '* (' . $suggestion['specialty'] . "). ¿Te la enviamos?\n\n*1.* Sí\n*2.* No", $messageId, 'suggest');
            return;
        }
        // Historia en camino o radicado enviado: la solicitud terminó.
        $this->offerAnother($phone, $messageId, $profile);
    }

    private function select(string $phone, string $messageId, string $text, array $data): void
    {
        $choice = ctype_digit($text) ? (int)$text : 0;
        if (!isset($data['ids'][$choice - 1])) { $this->reply($phone, 'Responde con el número de la atención.', $messageId, 'select-retry'); return; }
        $this->endSession($phone);
        try { $this->app->confirm((int)$data['request_id'], $data['ids'][$choice - 1]); }
        catch (\DomainException) { $this->reply($phone, 'Esa atención ya no está disponible. Escribe *HISTORIA* para empezar de nuevo.', $messageId, 'select-fail'); return; }
        $this->offerFor($phone, $messageId, $data);
    }

    private function suggestion(string $phone, string $messageId, array $words, array $data): void
    {
        $answer = self::yesNo($words);
        if ($answer === null) { $this->reply($phone, 'Responde *1* (sí) o *2* (no).', $messageId, 'suggest-retry'); return; }
        $this->endSession($phone);
        $id = (int)$data['request_id'];
        if ($answer) {
            try { $this->app->selectSuggestion($id, (int)$data['encounter_id']); $this->offerFor($phone, $messageId, $data); return; }
            catch (\DomainException) {}
        }
        // Sin historia elegida: queda para gestión y recibe el radicado.
        $this->app->event($id, 'whatsapp_intake', $answer ? 'suggestion_unavailable' : 'suggestion_rejected');
        $this->app->whatsapp()->queueNotFound($id);
        $this->offerFor($phone, $messageId, $data);
    }

    private function restart(string $phone, string $messageId): void
    {
        $this->endSession($phone);
        $this->reply($phone, 'La conversación venció. Escribe *HISTORIA* para empezar.', $messageId, 'expired');
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
