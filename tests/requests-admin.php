<?php
declare(strict_types=1);
// Gestión de solicitudes por la persona encargada (SCRUM-23): pendientes, asignar, «No registra» y plantillas.
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('America/Bogota');
$root = dirname(__DIR__);
$config = array_replace(require $root . '/config/default.php', require $root . '/config/local.php');
$databaseName = 'citaya_test_' . bin2hex(random_bytes(5));
$server = new PDO(preg_replace('/;dbname=[^;]+/', '', $config['db_dsn']), $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config['db_dsn'] = preg_replace('/dbname=[^;]+/', 'dbname=' . $databaseName, $config['db_dsn']);
$config['storage_path'] = $root . '/.runtime/' . $databaseName;
$config['mail_transport'] = 'local';
$config['whatsapp_transport'] = 'local';
$config['public_url'] = 'https://portal.example.test';
foreach (['histories', 'outbox', 'wa-outbox'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$db = new App\Database($config);
$app = new App\Application($db, $config);
$bot = $app->whatsapp();
$admin = new App\AdminDeliveries($db);
$templates = new App\MessageTemplates($app);
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function rejects(callable $fn, string $label, string $contains = ''): void {
    try { $fn(); } catch (DomainException $e) { check($contains === '' || str_contains($e->getMessage(), $contains), $label); return; }
    throw new RuntimeException('FAIL (no rechazó): ' . $label);
}
function waText(int $requestId, string $kind): string { global $db, $config; $row = $db->one('SELECT encrypted_body FROM whatsapp_outbox WHERE request_id=? AND kind=?', [$requestId, $kind]); return $row ? App\Crypto::open($row['encrypted_body'], $config['app_key']) : ''; }
function inbound(string $id, string $phone, string $text): array {
    return ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $phone, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
}
function viaWhatsApp(array $form, string $phone, string $id): int {
    global $app, $bot, $db, $config;
    $bot->receive(inbound($id, $phone, 'historia'));
    $bot->receive(inbound($id . '-doc', $phone, (string)random_int(7000000000, 7999999999))); // documento nuevo: enlace del formulario
    $row = $db->one("SELECT encrypted_body FROM whatsapp_outbox WHERE kind='text' ORDER BY id DESC LIMIT 1");
    preg_match('/wa=([a-f0-9]{48})/', App\Crypto::open($row['encrypted_body'], $config['app_key']), $m);
    return $app->createRequest($form, 'ip-' . $id, true, $bot->findLink($m[1]));
}
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $specialty = $db->insert('specialties', ['name' => 'Ortopedia']);
    $patient = $db->insert('patients', ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'Ana María Pérez', 'email' => '', 'active' => 1]);
    $encounter = $db->insert('encounters', ['patient_id' => $patient, 'specialty_id' => $specialty, 'attended_at' => date('Y-m-d 08:00:00', strtotime('-20 days')), 'admission' => 'ING-1', 'location' => 'Sede de prueba']);
    $package = $db->insert('packages', ['encounter_id' => $encounter, 'version' => 1, 'status' => 'available', 'published_at' => $app->now()]);
    $name = bin2hex(random_bytes(16)) . '.pdf'; $path = $config['storage_path'] . '/histories/' . $name;
    file_put_contents($path, "%PDF-1.4\nPDF ficticio\n%%EOF");
    $db->insert('clinical_files', ['package_id' => $package, 'storage_name' => $name, 'original_name' => 'doc.pdf', 'sort_order' => 1, 'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)]);
    $form = ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'ana maria perez', 'approximate_date' => date('Y-m-d', strtotime('-2 days')), 'specialty_id' => 0, 'email' => 'paciente@example.test'];

    // --- Cola de pendientes ---
    $wrongDate = viaWhatsApp($form, '573001112233', 'w1');
    $web = $app->createRequest(['email' => 'web@example.test', 'approximate_date' => date('Y-m-d', strtotime('-3 days'))] + $form, 'ip-web', true);
    $mismatch = viaWhatsApp(['full_name' => 'Ana Pérez Otra'] + $form, '573004445566', 'w2');
    check($app->request($wrongDate)['status'] === 'no_matches' && $admin->pendingCount() === 3, 'Las solicitudes sin historia aparecen como pendientes');
    $pending = $admin->search('', 1, false, true);
    check(array_column($pending['rows'], 'id') === [$wrongDate, $web, $mismatch], 'Pendientes ordenadas de la más antigua a la más reciente');
    // La página le sugiere su consulta: el aviso de «no encontrada» espera a que decida.
    check(waText($wrongDate, 'notice') === '' && $app->recentSuggestion($wrongDate), 'Con una consulta sugerida no se envía todavía el aviso de historia no encontrada');
    check($app->releaseDeferredNotices() === 0 && waText($wrongDate, 'notice') === '', 'El aviso en espera no sale antes de 20 minutos');
    $db->run('UPDATE requests SET created_at=? WHERE id=?', [date('Y-m-d H:i:s', time() - 1500), $wrongDate]);
    check($app->releaseDeferredNotices() === 1 && $app->releaseDeferredNotices() === 0, 'Si el paciente no decide en 20 minutos, recibe el radicado una sola vez');
    check(str_contains(waText($wrongDate, 'notice'), '24 horas'), 'El radicado usa la plantilla con el tiempo estimado');

    // --- Candidatas y asignación ---
    $candidates = $app->candidateEncounters($wrongDate);
    check(count($candidates) === 1 && $candidates[0]['name_matches'] && (int)$candidates[0]['id'] === $encounter, 'Se ofrece la atención del mismo documento aunque la fecha no coincida');
    check(!$app->candidateEncounters($mismatch)[0]['name_matches'], 'Con otro nombre la atención aparece marcada como «Nombre distinto»');
    rejects(fn() => $app->assignEncounter($mismatch, $encounter, 1), 'No se puede enviar si el nombre no coincide', 'No registra');
    check($app->request($mismatch)['status'] === 'no_matches', 'El rechazo no cambia la solicitud');
    $app->assignEncounter($wrongDate, $encounter, 1);
    $assigned = $app->request($wrongDate);
    check($assigned['status'] === 'queued' && $assigned['resolution'] === 'assigned' && (int)$assigned['resolved_by'] === 1 && (int)$assigned['encounter_id'] === $encounter, 'Asignar deja la solicitud en cola y registra quién respondió');
    check((bool)$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history' AND recipient='paciente@example.test'", [$wrongDate]) && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$wrongDate]), 'La historia asignada se encola por correo y WhatsApp');
    rejects(fn() => $app->assignEncounter($wrongDate, $encounter, 1), 'Una solicitud ya gestionada no se asigna dos veces', 'ya fue gestionada');
    $app->assignEncounter($web, $encounter, 1);
    check($app->request($web)['status'] === 'queued' && !$db->one('SELECT id FROM whatsapp_outbox WHERE request_id=?', [$web]), 'Una solicitud web asignada se envía solo por correo');
    check($admin->pendingCount() === 1, 'Las solicitudes asignadas salen de Pendientes');

    // --- Paquete dañado: la solicitud vuelve a la cola ---
    $broken = viaWhatsApp($form, '573007778899', 'w3');
    file_put_contents($path, 'alterado');
    rejects(fn() => $app->assignEncounter($broken, $encounter, 1), 'Un paquete alterado impide asignar');
    check($app->request($broken)['status'] === 'no_matches' && $app->request($broken)['resolution'] === null, 'Si el envío no se prepara, la solicitud sigue pendiente');
    file_put_contents($path, "%PDF-1.4\nPDF ficticio\n%%EOF");

    // --- «No registra» ---
    $app->markNotRegistered($mismatch, 1);
    $closed = $app->request($mismatch);
    check($closed['status'] === 'not_registered' && $closed['resolution'] === 'not_registered', '«No registra» cierra la solicitud');
    check(str_contains(waText($mismatch, 'not_registered'), $closed['reference']) && str_contains(waText($mismatch, 'not_registered'), 'Revisa tus datos') && str_contains(waText($mismatch, 'not_registered'), 'https://portal.example.test/?page=home'), 'El WhatsApp «No registra» incluye radicado, instrucción y enlace');
    $notice = $db->one("SELECT * FROM jobs WHERE request_id=? AND kind='notice'", [$mismatch]);
    check($notice && $notice['recipient'] === 'paciente@example.test' && !$notice['package_id'], 'Se encola el aviso por correo sin documentos');
    rejects(fn() => $app->markNotRegistered($mismatch, 1), 'No se responde dos veces', 'ya fue gestionada');
    $worker = new App\MailWorker($app);
    while ($worker->tick());
    $eml = quoted_printable_decode(file_get_contents($config['storage_path'] . '/outbox/' . $notice['id'] . '.eml'));
    check($db->one('SELECT status FROM jobs WHERE id=?', [$notice['id']])['status'] === 'simulated' && str_contains($eml, 'No encontramos una historia') && !str_contains($eml, '*') && !str_contains($eml, 'application/pdf'), 'El correo «No registra» sale sin asteriscos ni adjuntos');
    $unknownPatient = viaWhatsApp(['document_number' => '55555555'] + $form, '573001231234', 'w4');
    $app->markNotRegistered($unknownPatient, 1);
    while ($worker->tick());
    check($db->one("SELECT status FROM jobs WHERE request_id=? AND kind='notice'", [$unknownPatient])['status'] === 'simulated', 'El aviso llega aunque el documento no exista en el repositorio');

    // --- Plantillas ---
    rejects(fn() => $templates->save('pending', 'Radicado {radicado} y {clave}', 1), 'Rechaza variables no permitidas', '{clave}');
    rejects(fn() => $templates->save('not_registered', 'Tu solicitud {radicado} no registra.', 1), 'La plantilla «No registra» exige el enlace', '{enlace}');
    $templates->save('pending', 'Tu radicado es *{radicado}*. Respuesta en {horas} h.', 1);
    $custom = viaWhatsApp(['document_number' => '66666666'] + $form, '573009990000', 'w5');
    check(waText($custom, 'notice') === 'Tu radicado es *' . $app->request($custom)['reference'] . '*. Respuesta en 24 h.' && $templates->isCustom('pending'), 'Los mensajes usan la plantilla personalizada');
    $templates->reset('pending', 1);
    check(!$templates->isCustom('pending') && str_contains($templates->render('pending', ['horas' => 24]), 'en máximo'), 'Restaurar vuelve al texto predeterminado');
    check($templates->renderPlain('history_found', ['radicado' => 'HC-1', 'documentos' => 2, 'correo' => 'a@b.c']) === "Tu historia clínica está lista (radicado HC-1). Te enviamos 2 documento(s) por aquí y al correo a@b.c.", 'La versión de correo quita la negrita de WhatsApp');

    // --- Respuesta desde el cuadro de gestión (Aprobado / No Registra, comentario y adjuntos) ---
    $pdfSource = $config['storage_path'] . '/histories/respuesta.pdf';
    file_put_contents($pdfSource, "%PDF-1.4\nRespuesta ficticia\n%%EOF");
    $pngSource = $config['storage_path'] . '/histories/orden.png';
    file_put_contents($pngSource, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $txtSource = $config['storage_path'] . '/histories/nota.txt';
    file_put_contents($txtSource, 'texto plano');
    $approvedCase = viaWhatsApp(['document_number' => '77777777'] + $form, '573007770000', 'r1');
    $comment = 'Te enviamos tu historia clínica en los archivos adjuntos.';
    rejects(fn() => $app->respondCase($approvedCase, 1, 'approved', $comment, []), 'Aprobado exige adjuntar documentos', 'adjunta');
    rejects(fn() => $app->respondCase($approvedCase, 1, 'approved', 'corto', [['path' => $pdfSource, 'name' => 'a.pdf']]), 'Exige un comentario de al menos 10 caracteres', 'comentario');
    rejects(fn() => $app->respondCase($approvedCase, 1, 'approved', $comment, [['path' => $txtSource, 'name' => 'nota.txt']]), 'Solo acepta PDF, JPG o PNG (por contenido)', 'PDF, JPG o PNG');
    rejects(fn() => $app->respondCase($approvedCase, 1, 'otro', $comment, []), 'Rechaza un estado inventado', 'estado');
    $app->respondCase($approvedCase, 1, 'approved', $comment, [['path' => $pdfSource, 'name' => 'Historia completa.pdf'], ['path' => $pngSource, 'name' => '../orden<médica>.png']]);
    $approvedRequest = $app->request($approvedCase);
    $responseFiles = $db->all('SELECT * FROM response_files WHERE request_id=? ORDER BY sort_order', [$approvedCase]);
    $mailJob = $db->one("SELECT * FROM jobs WHERE request_id=? AND kind='response'", [$approvedCase]);
    check($approvedRequest['status'] === 'queued' && $approvedRequest['resolution'] === 'approved' && count($responseFiles) === 2 && (int)$responseFiles[0]['job_id'] === (int)$mailJob['id'] && $responseFiles[1]['wa_job_id'] && $responseFiles[1]['original_name'] === 'orden_médica_.png' && $responseFiles[1]['mime'] === 'image/png', 'Aprobado: guarda los adjuntos (nombre saneado) y los enlaza al correo y al WhatsApp');
    check(!str_contains((string)$approvedRequest['response_note'], 'historia') && App\Crypto::open($approvedRequest['response_note'], $config['app_key']) === $comment, 'El comentario se guarda cifrado');
    rejects(fn() => $app->respondCase($approvedCase, 1, 'not_registered', $comment, []), 'Una solicitud ya respondida no admite otra respuesta', 'ya fue gestionada');
    while ((new App\MailWorker($app))->tick());
    $eml = (string)file_get_contents($config['storage_path'] . '/outbox/' . $mailJob['id'] . '.eml');
    check($app->request($approvedCase)['status'] === 'simulated' && str_contains($eml, 'Historia completa.pdf') && str_contains($eml, 'image/png') && str_contains(quoted_printable_decode($eml), $comment), 'El correo lleva el comentario y los dos adjuntos, y actualiza el estado de la solicitud');
    $waJob = $db->one("SELECT * FROM whatsapp_outbox WHERE request_id=? AND kind='response'", [$approvedCase]);
    while ($bot->tick());
    $preview = json_decode((string)file_get_contents($config['storage_path'] . '/wa-outbox/' . $waJob['id'] . '.json'), true);
    check(array_column($preview['messages'], 'type') === ['text', 'document', 'photo'] && str_contains($preview['messages'][0]['body'], $comment) && $preview['messages'][2]['mime'] === 'image/png', 'WhatsApp envía el comentario, el PDF como documento y la imagen como foto');
    $detail = (new App\AdminDeliveries($db, $config['app_key']))->detail($approvedCase);
    check($detail['request']['response_note'] === $comment && $detail['deliveries'][0]['kind'] === 'response' && count($detail['deliveries'][0]['files']) === 2, 'El detalle muestra la respuesta y sus adjuntos');
    $timeline = (new App\AdminDeliveries($db, $config['app_key']))->timeline($approvedCase);
    $titles = array_column($timeline['entries'], 'title');
    $responseEntry = array_values(array_filter($timeline['entries'], fn($e) => $e['title'] === 'Respuesta de la persona encargada'))[0] ?? null;
    check($titles[0] === 'Radicación' && in_array('Búsqueda automática', $titles, true) && in_array('Respuesta «Aprobado» por correo', $titles, true) && in_array('Respuesta «Aprobado» por WhatsApp', $titles, true)
        && $responseEntry && $responseEntry['text'] === $comment && $responseEntry['badge'] === 'Aprobado' && $timeline['entries'] === array_values($timeline['entries']), 'Historial del caso: radicación, búsqueda, respuesta con su comentario y envíos por correo y WhatsApp');
    $dates = array_column($timeline['entries'], 'at'); $sorted = $dates; sort($sorted);
    check($dates === $sorted && (new App\AdminDeliveries($db))->timeline(999999) === null, 'Historial ordenado por fecha; un caso inexistente no devuelve datos');
    $rejectedCase = viaWhatsApp(['document_number' => '88888888'] + $form, '573008880000', 'r2');
    $pendingBefore = $admin->pendingCount();
    $app->respondCase($rejectedCase, 1, 'not_registered', 'No encontramos una historia con esos datos. Verifica tu documento.', []);
    check($app->request($rejectedCase)['status'] === 'not_registered' && $db->one("SELECT id FROM jobs WHERE request_id=? AND kind='notice' AND dedupe_key LIKE 'response:%'", [$rejectedCase]) && str_contains(waText($rejectedCase, 'not_registered'), 'Verifica tu documento') && $admin->pendingCount() === $pendingBefore - 1, 'No Registra sin adjuntos: avisa por correo y WhatsApp y sale de pendientes');

    // --- Aviso de «no encontrada» solo cuando corresponde ---
    $db->run('DELETE FROM rate_limits'); // base temporal: el límite por paciente ya se probó arriba
    $chosen = viaWhatsApp(['approximate_date' => date('Y-m-d', strtotime('-5 days'))] + $form, '573001119999', 'w0');
    $app->selectSuggestion($chosen, $encounter);
    check(waText($chosen, 'notice') === '' && $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$chosen]) && $app->releaseDeferredNotices() === 0, 'Si elige la consulta sugerida solo recibe la historia, nunca el aviso de «no encontrada»');
    $dated = viaWhatsApp(['approximate_date' => date('Y-m-d', strtotime('-6 days'))] + $form, '573001118888', 'w00');
    $app->changeFilters($dated, ['approximate_date' => date('Y-m-d', strtotime('-60 days')), 'specialty_id' => 0], true);
    check(str_contains(waText($dated, 'notice'), '24 horas'), 'Si indica una fecha aproximada sin historia, recibe el radicado de inmediato');

    // --- Plantillas «No registra» por motivo ---
    $reasonValues = ['radicado' => 'HC-1', 'nombre' => 'Ana Pérez', 'documento' => 'CC 123', 'fecha' => '15/09/2026', 'especialidad' => 'Ortopedia', 'enlace' => 'https://portal.example.test'];
    check(str_contains($templates->renderPlain('nr_document', $reasonValues), 'documento CC 123') && str_contains($templates->renderPlain('nr_date', $reasonValues), 'atención el 15/09/2026')
        && str_contains($templates->renderPlain('nr_name', $reasonValues), 'nombre Ana Pérez no coincide') && str_contains($templates->renderPlain('nr_specialty', $reasonValues), 'en Ortopedia'), 'Cada motivo de «No registra» dice exactamente qué revisar');
    rejects(fn() => $templates->save('nr_date', 'No encontramos atención el {fecha}.', 1), 'Las plantillas de motivo exigen el enlace para una nueva solicitud', '{enlace}');
    $db->run('DELETE FROM rate_limits');
    $reasonCase = viaWhatsApp(['document_number' => '55555555'] + $form, '573005550000', 'nr1');
    $reasonText = $templates->renderPlain('nr_date', ['radicado' => $app->request($reasonCase)['reference']] + $reasonValues);
    $app->respondCase($reasonCase, 1, 'not_registered', $reasonText, []);
    $sentReason = waText($reasonCase, 'not_registered');
    check($sentReason === $reasonText && substr_count($sentReason, $app->request($reasonCase)['reference']) === 1, 'WhatsApp envía el motivo sin repetir el radicado');
    echo "\nPASS: $checks comprobaciones. Sin envíos externos.\n";
} finally {
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories', 'outbox', 'wa-outbox', 'responses'] as $dir) { if (!is_dir($config['storage_path'] . '/' . $dir)) continue; foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
