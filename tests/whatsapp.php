<?php
declare(strict_types=1);
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
foreach (['histories','outbox','wa-outbox'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$db = new App\Database($config);
$app = new App\Application($db, $config);
$bot = $app->whatsapp();
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function inbound(string $id, string $phone, string $text): array {
    return ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $phone, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
}
function lastText(): string { global $db, $app; $row = $db->one("SELECT encrypted_body FROM whatsapp_outbox WHERE kind='text' ORDER BY id DESC LIMIT 1"); return App\Crypto::open($row['encrypted_body'], $app->config['app_key']); }
function linkToken(): string { preg_match('/wa=([a-f0-9]{48})/', lastText(), $m); return $m[1]; }
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $specialty = $db->insert('specialties', ['name' => 'Ortopedia']);
    $patient = $db->insert('patients', ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'Ana María Pérez', 'email' => '', 'active' => 1]);
    $encounter = $db->insert('encounters', ['patient_id' => $patient, 'specialty_id' => $specialty, 'attended_at' => date('Y-m-d 08:00:00', strtotime('-3 days')), 'admission' => 'ING-1', 'location' => 'Sede de prueba']);
    $package = $db->insert('packages', ['encounter_id' => $encounter, 'version' => 1, 'status' => 'available', 'published_at' => $app->now()]);
    foreach ([1, 2] as $i) {
        $name = bin2hex(random_bytes(16)) . '.pdf'; $path = $config['storage_path'] . '/histories/' . $name;
        file_put_contents($path, "%PDF-1.4\nPDF ficticio $i\n%%EOF");
        $db->insert('clinical_files', ['package_id' => $package, 'storage_name' => $name, 'original_name' => "doc$i.pdf", 'sort_order' => $i, 'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)]);
    }

    check(App\WhatsApp::validSignature('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":1}', 'secreto'), 'secreto'), 'Acepta firma válida de Meta');
    check(!App\WhatsApp::validSignature('{"a":1}', 'sha256=00', 'secreto') && !App\WhatsApp::validSignature('x', 'sha256=' . hash_hmac('sha256', 'x', ''), ''), 'Rechaza firma inválida o secreto vacío');

    check($bot->receive(inbound('wamid.1', '573001112233', 'Hola buenas')) === 1 && str_contains(lastText(), 'HISTORIA'), 'Saludo recibe el menú');
    check($bot->receive(inbound('wamid.2', '573001112233', 'Necesito mi historia clínica')) === 1 && str_contains(lastText(), 'https://portal.example.test/?page=home&wa='), 'Solicitud de historia recibe el enlace del formulario');
    check($bot->receive(inbound('wamid.2', '573001112233', 'Necesito mi historia clínica')) === 0, 'Un reintento del webhook no duplica la respuesta');
    $token = linkToken();
    check($db->one('SELECT COUNT(*) n FROM whatsapp_links')['n'] == 1 && !$db->one('SELECT id FROM whatsapp_links WHERE token_hash=?', [$token]), 'El enlace se guarda solo como hash');

    $linkId = $bot->findLink($token);
    check($linkId !== null && $bot->findLink(str_repeat('a', 48)) === null, 'Valida el token del enlace');
    $form = ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'ana maria perez', 'approximate_date' => date('Y-m-d', strtotime('-3 days')), 'specialty_id' => 0, 'email' => 'paciente@example.test'];
    $found = $app->createRequest($form, 'ip-1', true, $linkId);
    $request = $app->request($found);
    check($request['status'] === 'queued' && $request['whatsapp_phone'] === '573001112233', 'Historia encontrada: solicitud vinculada al WhatsApp y en cola');
    check((bool)$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history' AND recipient='paciente@example.test'", [$found]), 'Historia encontrada: se encola el correo');
    check((bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history' AND package_id=?", [$found, $package]), 'Historia encontrada: se encola el envío por WhatsApp');
    check($bot->findLink($token) === null, 'El enlace no puede reutilizarse');

    while ($bot->tick());
    $outbox = $db->one("SELECT * FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$found]);
    $preview = json_decode(file_get_contents($config['storage_path'] . '/wa-outbox/' . $outbox['id'] . '.json'), true);
    check($outbox['status'] === 'simulated' && $outbox['encrypted_body'] === null, 'Procesa el envío por WhatsApp en modo local');
    check(count($preview['messages']) === 3 && $preview['messages'][1]['type'] === 'document' && str_contains($preview['messages'][0]['body'], $request['reference']), 'Envía un texto con el radicado y cada PDF como documento');

    $bot->receive(inbound('wamid.3', '573009998877', 'historia'));
    $missing = $app->createRequest(['document_number' => '99999999'] + $form, 'ip-2', true, $bot->findLink(linkToken()));
    $missingRequest = $app->request($missing);
    $notice = $db->one("SELECT * FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$missing]);
    check($missingRequest['status'] === 'no_matches' && $notice && $notice['phone'] === '573009998877', 'Sin historia: se encola el radicado por WhatsApp');
    $noticeText = App\Crypto::open($notice['encrypted_body'], $config['app_key']);
    check(str_contains($noticeText, $missingRequest['reference']) && str_contains($noticeText, '24 horas'), 'El mensaje incluye el radicado y el tiempo estimado de 24 horas');
    check(!$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history'", [$missing]) && !$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$missing]), 'Sin historia: no se envían documentos');

    $webOnly = $app->createRequest(['email' => 'otro@example.test'] + $form, 'ip-3', true);
    check(!$db->one('SELECT id FROM whatsapp_outbox WHERE request_id=?', [$webOnly]), 'Las solicitudes desde la web no generan mensajes de WhatsApp');

    $db->run("UPDATE whatsapp_links SET expires_at=? WHERE used_at IS NULL", [date('Y-m-d H:i:s', time() - 1)]);
    $bot->receive(inbound('wamid.4', '573005554433', 'hc'));
    $expired = $db->one('SELECT id FROM whatsapp_links WHERE phone=?', ['573005554433'])['id'];
    $db->run('UPDATE whatsapp_links SET expires_at=? WHERE id=?', [date('Y-m-d H:i:s', time() - 1), $expired]);
    $late = $app->createRequest(['email' => 'tarde@example.test'] + $form, 'ip-4', true, (int)$expired);
    check($app->request($late)['whatsapp_phone'] === null, 'Un enlace vencido no vincula el WhatsApp');
    // --- Órdenes médicas ---
    check(App\MedicalOrders::detect(["Nota\nEl paciente refiere que la fórmula médica fue entregada\n"]) === [], 'Una mención dentro de una nota clínica no cuenta como orden');
    check(array_column(App\MedicalOrders::detect(["Hoja 1", "FÓRMULA MÉDICA\nAcetaminofén", "Orden de imágenes diagnósticas:\nRx"]), 'type') === ['formula', 'imagenes'], 'Detecta fórmula e imágenes por título, con tildes y mayúsculas');

    // --- Historia cargada después: la solicitud pendiente se envía sola ---
    require_once $root . '/tests/TestPdf.php';
    $late = ['document_number' => '2000000002', 'full_name' => 'Paciente ficticio de prueba de carga', 'email' => 'espera@example.test', 'approximate_date' => date('Y-m-d')] + $form;
    $bot->receive(inbound('wamid.5', '573007776655', 'historia'));
    $pendingId = $app->createRequest($late, 'ip-5', true, $bot->findLink(linkToken()));
    check($app->request($pendingId)['status'] === 'no_matches' && $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$pendingId]), 'Sin historia: queda pendiente con su radicado informado');
    $pdf = sys_get_temp_dir() . '/cy-orders-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($pdf, testPdf('2000000002', 'INGORD1', '', ['FORMULA MEDICA', 'Acetaminofen 500 mg', 'Orden de laboratorio:', 'Hemograma']));
    $repository = new App\Repository($app);
    $uploaded = $repository->importPackage([['path' => $pdf, 'name' => 'historia.pdf']]);
    $summary = $repository->summary($uploaded);
    check($summary['document'] === 'CC 2000000002' && $summary['admission'] === 'INGORD1' && $summary['files'] === 1 && !$summary['reused'], 'El resumen de la carga muestra el paciente, el ingreso y los archivos leídos');
    check($summary['orders'] === ['Fórmula médica', 'Orden de laboratorio'], 'El resumen de la carga lista las órdenes detectadas');
    check($summary['resolved'] === [['reference' => $app->request($pendingId)['reference'], 'sent' => true]], 'El resumen indica a qué solicitud pendiente se envió la historia');
    $repository->importPackage([['path' => $pdf, 'name' => 'historia.pdf']]);
    $again = $repository->summary($uploaded);
    check($again['reused'] && $again['resolved'] === [], 'Recargar los mismos archivos se informa como ya registrada, sin nuevos envíos');
    unlink($pdf);
    check($app->request($pendingId)['status'] === 'queued' && $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history' AND package_id=?", [$pendingId, $uploaded]), 'Al cargar la historia, la solicitud pendiente se envía sola por WhatsApp');
    check((bool)$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history' AND recipient='espera@example.test'", [$pendingId]), 'Al cargar la historia, también se encola el correo');
    check($db->one('SELECT COUNT(*) n FROM clinical_orders')['n'] == 2, 'Se guardan las órdenes detectadas al importar el PDF');
    while ($bot->tick());
    $sent = $db->one("SELECT * FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$pendingId]);
    $preview = json_decode(file_get_contents($config['storage_path'] . '/wa-outbox/' . $sent['id'] . '.json'), true);
    $types = array_column($preview['messages'], 'type');
    check($sent['status'] === 'simulated' && $types === ['text', 'document', 'text', 'image'], 'Envía historia, guía de órdenes y una imagen por página con órdenes');
    check(str_contains($preview['messages'][3]['caption'], 'Fórmula médica / Orden de laboratorio'), 'La imagen de la página lleva el título de cada orden');
    check(str_contains($preview['messages'][2]['body'], 'Fórmula médica') && str_contains($preview['messages'][2]['body'], 'reclamar tus medicamentos en tu farmacia') && str_contains($preview['messages'][2]['body'], 'Orden de laboratorio'), 'La guía explica cada orden detectada');
    check($app->resolveWaitingRequests($uploaded) === [] && $db->one("SELECT COUNT(*) n FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$pendingId])['n'] == 1, 'Recargar la misma historia no duplica el envío');

    // --- WhatsApp escrito en el formulario web (RF-29) ---
    check(App\WhatsApp::normalizePhone('300 123 4567') === '573001234567' && App\WhatsApp::normalizePhone('+1 (415) 555-0100') === '14155550100', 'Normaliza el WhatsApp escrito en la web');
    try { App\WhatsApp::normalizePhone('12345'); $invalid = false; } catch (DomainException) { $invalid = true; }
    check($invalid, 'Rechaza un WhatsApp inválido');
    $webId = $app->createRequest(['email' => 'web@example.test'] + $form, 'ip-6', true);
    $code = $bot->claimNumber($webId, '573001239999');
    check($app->request($webId)['status'] === 'queued' && !$db->one('SELECT id FROM whatsapp_outbox WHERE request_id=?', [$webId]) && !$db->one('SELECT id FROM requests WHERE whatsapp_code_hash=?', [$code]), 'El número escrito no recibe nada antes de confirmarlo y el código se guarda como hash');
    $bot->receive(inbound('wamid.c1', '573001239999', 'CONFIRMAR ZZZZZZ'));
    check($app->request($webId)['whatsapp_phone'] === null && str_contains(lastText(), 'no es válido'), 'Un código incorrecto no vincula el número');
    $bot->receive(inbound('wamid.c2', '573001239999', 'confirmar ' . strtolower($code)));
    check($app->request($webId)['whatsapp_phone'] === '573001239999' && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$webId]), 'Al confirmar, la historia ya enviada por correo sale también por WhatsApp');
    $bot->receive(inbound('wamid.c3', '573001239999', 'CONFIRMAR ' . $code));
    check(str_contains(lastText(), 'no es válido'), 'El código no se puede usar dos veces');
    $webMissing = $app->createRequest(['document_number' => '99999999', 'email' => 'web2@example.test'] + $form, 'ip-7', true);
    $code2 = $bot->claimNumber($webMissing, '573001238888');
    $bot->receive(inbound('wamid.c4', '573001238888', 'CONFIRMAR ' . $code2));
    check($app->request($webMissing)['whatsapp_phone'] === '573001238888' && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$webMissing]), 'Al confirmar una solicitud pendiente se envía el radicado por WhatsApp');
    $webExpired = $app->createRequest(['email' => 'web3@example.test'] + $form, 'ip-8', true);
    $code3 = $bot->claimNumber($webExpired, '573001237777');
    $db->run('UPDATE requests SET whatsapp_code_expires=? WHERE id=?', [date('Y-m-d H:i:s', time() - 60), $webExpired]);
    $bot->receive(inbound('wamid.c5', '573001237777', 'CONFIRMAR ' . $code3));
    check($app->request($webExpired)['whatsapp_phone'] === null, 'Un código vencido no vincula el número');
    while ($bot->tick());

    // --- Ventana de 24 h ---
    $reflection = new ReflectionMethod($bot, 'waitForWindow');
    $db->run("UPDATE whatsapp_outbox SET status='sending',progress=1 WHERE id=?", [$sent['id']]);
    $job = $db->one('SELECT * FROM whatsapp_outbox WHERE id=?', [$sent['id']]);
    $reflection->invoke($bot, $job);
    $waiting = $db->one('SELECT * FROM whatsapp_outbox WHERE id=?', [$sent['id']]);
    check($waiting['status'] === 'waiting_window' && $waiting['progress'] == 1, 'Con la ventana cerrada el mensaje espera conservando lo ya enviado');
    check($bot->tick() === false, 'Un mensaje en espera no se reintenta solo');
    $bot->receive(inbound('wamid.6', '573007776655', 'hola'));
    check($db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$sent['id']])['status'] === 'queued' && str_contains(lastText(), 'Te estamos enviando'), 'Cuando el paciente escribe, el mensaje en espera vuelve a la cola');
    $db->run("UPDATE whatsapp_outbox SET status='waiting_window',created_at=? WHERE id=?", [date('Y-m-d H:i:s', time() - 86400 * 8), $sent['id']]);
    $bot->tick();
    check($db->one('SELECT last_error FROM whatsapp_outbox WHERE id=?', [$sent['id']])['last_error'] === 'window_expired', 'Un mensaje en espera más de 7 días se marca vencido');
    echo "\nPASS: $checks comprobaciones. Sin envíos externos.\n";
} finally {
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories','outbox','wa-outbox'] as $dir) { foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
