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
/** HISTORIA y un documento sin solicitudes previas: el bot responde con el enlace del formulario. */
function askLink(string $id, string $phone): string { global $bot; $bot->receive(inbound($id, $phone, 'historia')); $bot->receive(inbound($id . '-doc', $phone, (string)random_int(7000000000, 7999999999))); return linkToken(); }
/** Último texto encolado para un número. */
function lastTo(string $phone): string { global $db, $app; $row = $db->one("SELECT encrypted_body FROM whatsapp_outbox WHERE phone=? AND kind='text' ORDER BY id DESC LIMIT 1", [$phone]); return $row ? App\Crypto::open($row['encrypted_body'], $app->config['app_key']) : ''; }
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

    check($bot->receive(inbound('wamid.1', '573001112233', 'Hola buenas')) === 1 && str_contains(lastText(), 'Solicitar historia clínica'), 'Saludo recibe el menú');
    check($bot->receive(inbound('wamid.2', '573001112233', 'Necesito mi historia clínica')) === 1 && str_contains(lastText(), 'número de documento'), 'Solicitud de historia: el bot pide el número de documento');
    check($bot->receive(inbound('wamid.2', '573001112233', 'Necesito mi historia clínica')) === 0, 'Un reintento del webhook no duplica la respuesta');
    $beforeDoc = (int)$db->one("SELECT COUNT(*) n FROM whatsapp_outbox WHERE kind='text'")['n'];
    $bot->receive(inbound('wamid.2b', '573001112233', '1.012.345.678'));
    check(str_contains(lastText(), 'https://portal.example.test/?page=home&wa=') && !$db->one("SELECT phone FROM whatsapp_sessions WHERE phone='573001112233'"), 'Primera vez del documento: recibe el enlace del formulario');
    check((int)$db->one("SELECT COUNT(*) n FROM whatsapp_outbox WHERE kind='text'")['n'] === $beforeDoc + 1 && !str_contains(lastText(), 'No tenemos solicitudes'), 'Primera vez: solo llega el mensaje del formulario con su enlace');
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

    $missing = $app->createRequest(['document_number' => '99999999'] + $form, 'ip-2', true, $bot->findLink(askLink('wamid.3', '573009998877')));
    $missingRequest = $app->request($missing);
    $notice = $db->one("SELECT * FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$missing]);
    check($missingRequest['status'] === 'no_matches' && $notice && $notice['phone'] === '573009998877', 'Sin historia: se encola el radicado por WhatsApp');
    $noticeText = App\Crypto::open($notice['encrypted_body'], $config['app_key']);
    check(str_contains($noticeText, $missingRequest['reference']) && str_contains($noticeText, '24 horas'), 'El mensaje incluye el radicado y el tiempo estimado de 24 horas');
    check(!$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history'", [$missing]) && !$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$missing]), 'Sin historia: no se envían documentos');

    $webOnly = $app->createRequest(['email' => 'otro@example.test'] + $form, 'ip-3', true);
    check(!$db->one('SELECT id FROM whatsapp_outbox WHERE request_id=?', [$webOnly]), 'Las solicitudes desde la web no generan mensajes de WhatsApp');

    $db->run("UPDATE whatsapp_links SET expires_at=? WHERE used_at IS NULL", [date('Y-m-d H:i:s', time() - 1)]);
    askLink('wamid.4', '573005554433');
    $expired = $db->one('SELECT id FROM whatsapp_links WHERE phone=?', ['573005554433'])['id'];
    $db->run('UPDATE whatsapp_links SET expires_at=? WHERE id=?', [date('Y-m-d H:i:s', time() - 1), $expired]);
    $late = $app->createRequest(['email' => 'tarde@example.test'] + $form, 'ip-4', true, (int)$expired);
    check($app->request($late)['whatsapp_phone'] === null, 'Un enlace vencido no vincula el WhatsApp');
    // --- Órdenes médicas ---
    check(App\MedicalOrders::detect(["Nota\nEl paciente refiere que la fórmula médica fue entregada\n"]) === [], 'Una mención dentro de una nota clínica no cuenta como orden');
    $extramural = App\MedicalOrders::detect([
        "ORDENES MEDICAS EXTRAMURALES\nLABORATORIOS EXTRAMURAL:\nMEDICAMENTOS EXTRAMURAL:\nINTERCONSULTAS EXTRAMURAL:\n",
        "SOLICITUD MEDICAMENTOS AMBULATORIOS\nMEDICAMENTOS EXTRAMURAL:\n",
        "SOLICITUD LABORATORIOS EXTRAMURAL\nLABORATORIOS EXTRAMURAL:\n",
        "SOLICITUD AYUDAS DIAGNÓSTICAS EXTRAMURAL\nIMAGENES DX EXTRAMURAL:\n",
        "SOLICITUD INTERCONSULTAS EXTRAMURAL\nINTERCONSULTAS EXTRAMURAL:\n",
    ]);
    check(array_map(fn($o) => [$o['type'], $o['page']], $extramural) === [['formula', 2], ['laboratorio', 3], ['imagenes', 4], ['remision', 5]], 'Reconoce el formato «SOLICITUD … EXTRAMURAL» y no cuenta los campos vacíos del resumen');
    $imaging = App\MedicalOrders::details('imagenes', "SOLICITUD AYUDAS DIAGNOSTICAS EXTRAMURAL\nPLAN DE MANEJO\nCódigo Servicio Cantidad\nIMAGENES DX EXTRAMURAL:\nLateralidad Fecha sugerida\n10/07/2026 9:51:00 a. m.No aplica\t1ECOGRAFIA PELVICA GINECOLOGICA TRANSVAGINAL. 881401              \nIMPRESIÓN DIAGNOSTICA\nDIAGNOSTICO DE PRUEBA\nProfesional:\n180 Vigencia:\n");
    check($imaging === ['items' => ['*Ecografia pelvica ginecologica transvaginal*'], 'when' => null, 'validity' => 180], 'Lee el estudio ordenado y la vigencia de la orden de imágenes');
    $labs = App\MedicalOrders::details('laboratorio', "PLAN DE MANEJO\nCódigo Servicio Cantidad\nLABORATORIOS EXTRAMURAL:\n901107              COLORACION GRAM Y LECTURA PARA CUALQUIER MUESTRA. \t1\nDatos Clínicos Relevantes: PRUEBA\n901305              EXAMEN DIRECTO PARA HONGOS (KOH). \t1\nIMPRESIÓN DIAGNOSTICA\n");
    check($labs['items'] === ['*Coloracion gram y lectura para cualquier muestra*', '*Examen directo para hongos (koh)*'], 'Lee cada examen de la orden de laboratorio');
    $referral = App\MedicalOrders::details('remision', "PLAN DE MANEJO\nINTERCONSULTAS EXTRAMURAL:\nEspecialidad\nObservaciones:\nCONSULTA DE PRIMERA VEZ POR ESPECIALISTA \nEN CIRUGIA VASCULAR. \n1890201              \nCIRUGIA VASCULAR\nCONSULTA DE CONTROL O DE SEGUIMIENTO POR ESPECIALISTA EN DERMATOLOGIA. \n1890302\n1 MES Y MEDIO\nDERMATOLOGIA\nIMPRESIÓN DIAGNOSTICA\n");
    check($referral['items'] === ['*Consulta de primera vez por especialista en cirugia vascular*', '*Consulta de control o de seguimiento por especialista en dermatologia*'] && $referral['when'] === '1 mes y medio', 'Lee cada interconsulta (sin mezclar especialidades) y cuándo pedir la cita');
    $formula = App\MedicalOrders::details('formula', "PLAN DE MANEJO\nMEDICAMENTOS EXTRAMURAL:\nCantidadMedicamento DuraciónDCI En letras\nACETAMINOFEN 500 mg Tableta Administrar 500 mg cada 8 Hora(s) de forma \nORAL por 5 Dia(s)\n5 Días( Quince )\nIndicaciones:\tDESPUES DE COMER\nJustificación clínica PBS:\nIMPRESIÓN DIAGNÓSTICA\n");
    check($formula['items'] === ['*Acetaminofen 500 mg tableta*: 500 mg cada 8 hora(s) de forma oral por 5 dia(s) (despues de comer)'], 'Lee cada medicamento con su dosis, duración e indicaciones');
    $plain = new ReflectionMethod($bot, 'orderText');
    $echo = $plain->invoke($bot, 'imagenes', ['items' => ['*Ecografia pelvica ginecologica transvaginal*'], 'when' => null, 'validity' => 180]);
    check($echo === "🩻 *Ecografia pelvica ginecologica transvaginal*\n👉 Pide una cita en tu EPS para hacerte este examen.\n⏳ Tienes 180 días para usar esta orden.", 'Una orden de imágenes se explica en tres líneas: qué es, qué hacer y vigencia');
    $visit = $plain->invoke($bot, 'remision', ['items' => ['*Consulta de control o de seguimiento por especialista en dermatologia*'], 'when' => '1 mes y medio', 'validity' => null]);
    check(str_starts_with($visit, '🩺 *Cita de control con dermatologia*') && str_contains($visit, 'para dentro de *1 mes y medio*'), 'La interconsulta se dice como «Cita de control con …» y para cuándo');
    $meds = $plain->invoke($bot, 'formula', ['items' => ['*Acetaminofen 500 mg tableta*: 500 mg cada 8 hora(s) de forma oral por 5 dia(s)'], 'when' => null, 'validity' => null]);
    check(str_contains($meds, '*Acetaminofen 500 mg tableta*: 500 mg cada 8 horas, por boca, durante 5 días'), 'La dosis se escribe en palabras sencillas');
    check(App\MedicalOrders::details('imagenes', "Texto sin formato reconocible") === ['items' => [], 'when' => null, 'validity' => null], 'Sin formato reconocible no inventa detalles');
    check(array_column(App\MedicalOrders::detect(["Hoja 1", "FÓRMULA MÉDICA\nAcetaminofén", "Orden de imágenes diagnósticas:\nRx"]), 'type') === ['formula', 'imagenes'], 'Detecta fórmula e imágenes por título, con tildes y mayúsculas');

    // --- Historia cargada después: la solicitud pendiente se envía sola ---
    require_once $root . '/tests/TestPdf.php';
    $late = ['document_number' => '2000000002', 'full_name' => 'Paciente ficticio de prueba de carga', 'email' => 'espera@example.test', 'approximate_date' => date('Y-m-d')] + $form;
    $pendingId = $app->createRequest($late, 'ip-5', true, $bot->findLink(askLink('wamid.5', '573007776655')));
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
    check(str_contains($preview['messages'][3]['caption'], '💊 *Medicamentos*') && str_contains($preview['messages'][3]['caption'], '🧪 *Exámenes de laboratorio*'), 'La imagen de la página lleva cada orden con un título sencillo');
    check(str_contains($preview['messages'][2]['body'], 'lo que debes hacer') && str_contains($preview['messages'][3]['caption'], 'Reclama tus medicamentos en la farmacia') && str_contains($preview['messages'][3]['caption'], 'ayunas') && mb_strlen($preview['messages'][3]['caption']) < 400, 'Cada orden dice en una línea qué hacer (fórmula: farmacia; laboratorio: cita y ayunas)');
    $templates = new App\MessageTemplates($app);
    $templates->save('order_formula', 'Reclama tus medicamentos en la farmacia Central.', 1);
    $ordersAgain = (new ReflectionMethod($bot, 'orderMessages'))->invoke($bot, $app->packageFiles($uploaded));
    check(str_contains($ordersAgain[1]['caption'], 'farmacia Central'), 'Las indicaciones de cada orden se editan en Plantillas');
    $templates->reset('order_formula', 1);
    check($app->resolveWaitingRequests($uploaded) === [] && $db->one("SELECT COUNT(*) n FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$pendingId])['n'] == 1, 'Recargar la misma historia no duplica el envío');

    // --- WhatsApp escrito en el formulario web (RF-29) ---
    check(App\WhatsApp::normalizePhone('300 123 4567') === '573001234567' && App\WhatsApp::normalizePhone('+1 (415) 555-0100') === '14155550100', 'Normaliza el WhatsApp escrito en la web');
    try { App\WhatsApp::normalizePhone('12345'); $invalid = false; } catch (DomainException) { $invalid = true; }
    check($invalid, 'Rechaza un WhatsApp inválido');
    $webId = $app->createRequest(['email' => 'web@example.test'] + $form, 'ip-6', true);
    $code = $bot->claimNumber($webId, '573001239999');
    check($app->request($webId)['status'] === 'queued' && !$db->one('SELECT id FROM whatsapp_outbox WHERE request_id=?', [$webId]) && !$db->one('SELECT id FROM requests WHERE whatsapp_code_hash=?', [$code]), 'El número escrito no recibe nada antes de confirmarlo y el código se guarda como hash');
    $bot->receive(inbound('wamid.c1', '573001239999', 'CONFIRMAR ZZZZZZ'));
    check($app->request($webId)['whatsapp_phone'] === null && str_contains(lastText(), 'no válido'), 'Un código incorrecto no vincula el número');
    $bot->receive(inbound('wamid.c2', '573001239999', 'confirmar ' . strtolower($code)));
    check($app->request($webId)['whatsapp_phone'] === '573001239999' && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$webId]), 'Al confirmar, la historia ya enviada por correo sale también por WhatsApp');
    $bot->receive(inbound('wamid.c3', '573001239999', 'CONFIRMAR ' . $code));
    check(str_contains(lastText(), 'no válido'), 'El código no se puede usar dos veces');
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

    // --- Respuesta «Aprobado» con PDF: también se leen sus órdenes ---
    $answered = $app->createRequest(['document_number' => '3000000003', 'full_name' => 'Paciente ficticio de respuesta', 'email' => 'respuesta@example.test'] + $form, 'ip-r1', true, $bot->findLink(askLink('wamid.r1', '573005554433')));
    $responsePdf = $config['storage_path'] . '/histories/respuesta-orden.pdf';
    file_put_contents($responsePdf, testPdf('3000000003', 'INGRESP1', '', ['SOLICITUD AYUDAS DIAGNOSTICAS EXTRAMURAL', 'PLAN DE MANEJO', '881401 ECOGRAFIA PELVICA GINECOLOGICA. 1']));
    $app->respondCase($answered, 1, 'approved', 'Te enviamos tu historia clínica.', [['path' => $responsePdf, 'name' => 'historia.pdf']]);
    $responseJob = $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='response'", [$answered]);
    while ($bot->tick());
    $responsePreview = json_decode((string)file_get_contents($config['storage_path'] . '/wa-outbox/' . $responseJob['id'] . '.json'), true);
    $responseTypes = array_column($responsePreview['messages'], 'type');
    check($responseTypes === ['text', 'document', 'text', 'image'] && str_contains($responsePreview['messages'][3]['caption'], 'Pide una cita en tu EPS'), 'La respuesta «Aprobado» con PDF también explica las órdenes del documento');

    // --- Solicitud conversando con el bot: datos guardados del formulario ---
    $db->run('DELETE FROM rate_limits'); // límite de 5 solicitudes por documento y hora
    $app->createRequest(['full_name' => 'Ana María Pérez', 'email' => 'paciente@example.test'] + $form, 'ip-p0', true, $bot->findLink(askLink('wamid.p0', '573001112233')));
    $profile = $db->one("SELECT * FROM patient_profiles WHERE document_type='CC' AND document_number='1012345678'");
    check($profile && $profile['email'] === 'paciente@example.test' && $profile['whatsapp_phone'] === '573001112233', 'El formulario guarda los datos del paciente y el WhatsApp del enlace');
    $app->createRequest(['full_name' => 'Persona Distinta', 'email' => 'intruso@example.test'] + $form, 'ip-p1', true);
    check($db->one('SELECT email FROM patient_profiles WHERE id=?', [$profile['id']])['email'] === 'paciente@example.test', 'Una solicitud con otro nombre no reemplaza los datos guardados');
    $app->createRequest(['email' => 'nuevo@example.test'] + $form, 'ip-p2', true);
    check($db->one('SELECT email,whatsapp_phone FROM patient_profiles WHERE id=?', [$profile['id']]) === ['email' => 'nuevo@example.test', 'whatsapp_phone' => '573001112233'], 'Una nueva solicitud del paciente actualiza el correo y conserva el WhatsApp');
    $db->run("UPDATE patient_profiles SET full_name='Ana María Pérez',email='paciente@example.test' WHERE id=?", [$profile['id']]);

    $db->run('DELETE FROM rate_limits');
    $owner = '573001112233';
    $say = function (string $phone, string $text) use ($bot) { static $n = 0; $bot->receive(inbound('wamid.i' . (++$n), $phone, $text)); return lastTo($phone); };
    $say($owner, 'historia');
    check(str_contains($say($owner, 'abc'), 'solo el número'), 'Un documento inválido se vuelve a pedir');
    $shown = $say($owner, '1012345678');
    check(str_contains($shown, 'Ana María Pérez') && str_contains($shown, 'CC 1012345678') && str_contains($shown, 'paciente@example.test'), 'Desde el mismo WhatsApp muestra los datos guardados para confirmarlos');
    check(str_contains($say($owner, 'tal vez'), 'Responde *1*'), 'Una respuesta no reconocida se vuelve a preguntar');
    check(str_contains($say($owner, 'Sí'), 'fecha'), 'Datos confirmados: pregunta la fecha de la atención');
    check(str_contains($say($owner, '31/02/2026'), 'no válida') && str_contains($say($owner, date('d/m/Y', strtotime('+2 days'))), 'no válida'), 'Rechaza fechas inexistentes o futuras');
    $list = $say($owner, date('d/m/Y', strtotime('-3 days')));
    check(str_contains($list, '*1.* Ortopedia') && str_contains($list, '*0.* No recuerdo'), 'Pregunta la especialidad con opciones numeradas');
    check(str_contains($say($owner, '9'), 'No reconocimos esa especialidad'), 'Una especialidad fuera de la lista se vuelve a pedir');
    $before = (int)$db->one('SELECT MAX(id) n FROM requests')['n'];
    $say($owner, '1');
    $chat = $db->one('SELECT * FROM requests WHERE id>? ORDER BY id DESC LIMIT 1', [$before]);
    check($chat && $chat['status'] === 'queued' && $chat['whatsapp_phone'] === $owner && $chat['contact_email'] === 'paciente@example.test' && $chat['claimed_name'] === 'Ana María Pérez' && (int)$chat['specialty_id'] === $specialty, 'Se radica la solicitud con los datos guardados y queda vinculada al chat');
    check((bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history' AND phone=?", [$chat['id'], $owner]) && (bool)$db->one("SELECT id FROM jobs WHERE request_id=? AND kind='history' AND recipient='paciente@example.test'", [$chat['id']]), 'Historia encontrada: sale por el chat y al correo guardado');
    check(!$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', [$owner]), 'Al radicar termina la conversación');

    // Fecha desconocida: el bot ofrece la atención más reciente.
    $say($owner, 'historia'); $say($owner, '1012345678'); $say($owner, '1'); $say($owner, '0');
    $offer = $say($owner, '0');
    check(str_contains($offer, 'más reciente') && str_contains($offer, date('d/m/Y', strtotime('-3 days'))), 'Sin fecha: ofrece la atención más reciente disponible');
    $say($owner, '1');
    $suggested = $db->one('SELECT * FROM requests ORDER BY id DESC LIMIT 1');
    check($suggested['status'] === 'queued' && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$suggested['id']]), 'Al aceptar la sugerencia se envía esa historia');
    $say($owner, 'historia'); $say($owner, '1012345678'); $say($owner, '1'); $say($owner, '0'); $say($owner, '0');
    $say($owner, '2');
    $rejected = $db->one('SELECT * FROM requests ORDER BY id DESC LIMIT 1');
    check($rejected['status'] === 'no_matches' && (bool)$db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='notice'", [$rejected['id']]), 'Si no es esa atención, queda pendiente y recibe el radicado');

    // Datos incorrectos o cancelar.
    $say($owner, 'historia'); $say($owner, '1012345678');
    check(str_contains($say($owner, '2'), 'wa=') && !$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', [$owner]), 'Si los datos no son correctos recibe el formulario para corregirlos');
    $say($owner, 'historia');
    check(str_contains($say($owner, 'menu'), 'Solicitar historia clínica') && !$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', [$owner]), 'MENU cancela la conversación y muestra el menú');

    // Otro WhatsApp: debe escribir el nombre completo; el correo se muestra oculto.
    $other = '573004445566';
    $say($other, 'historia');
    $ask = $say($other, '1012345678');
    check(str_contains($ask, 'nombre completo') && !str_contains($ask, 'paciente@example.test'), 'Desde otro WhatsApp pide el nombre antes de mostrar datos');
    check(str_contains($say($other, 'Ana Pérez'), 'no coincide'), 'Un nombre incompleto no muestra los datos');
    $masked = $say($other, 'ANA MARIA PEREZ');
    check(str_contains($masked, 'pa******@example.test') && !str_contains($masked, 'paciente@'), 'Con el nombre correcto muestra los datos con el correo oculto');
    $say($other, 'menu');
    $say($other, 'historia'); $say($other, '1012345678'); $say($other, 'Otra Persona');
    check(str_contains($say($other, 'Otra Persona'), 'wa='), 'Tras dos nombres incorrectos recibe el formulario');
    check(App\WhatsAppIntake::parseDate('5-3-26') === '2026-03-05' && App\WhatsAppIntake::parseDate('2026/03/05') === '2026-03-05' && App\WhatsAppIntake::parseDate('ayer') === false, 'Entiende fechas día/mes/año y año/mes/día');
    $written = array_map([App\WhatsAppIntake::class, 'parseDate'], ['10 de julio del 2026', '10 julio 2026', '10 jul. 2026', 'Julio 10, 2026', '3 setiembre 2025']);
    check($written === ['2026-07-10', '2026-07-10', '2026-07-10', '2026-07-10', '2025-09-03'], 'Entiende fechas con el mes escrito («10 de julio del 2026»)');
    check(App\WhatsAppIntake::parseDate('31 de febrero de 2026') === false && App\WhatsAppIntake::parseDate('10 de jupiter 2026') === false && App\WhatsAppIntake::parseDate('1 de enero de ' . (date('Y') + 1)) === false, 'Rechaza fechas escritas inexistentes, meses inventados o futuras');
    $lastYear = (int)date('Y') - 1;
    check(App\WhatsAppIntake::parseDate('10 de julio') === (date('m-d') >= '07-10' ? date('Y') : (string)$lastYear) . '-07-10', 'Sin año toma la fecha ya pasada más reciente');
    $say($owner, 'historia'); $say($owner, '1012345678'); $say($owner, '1');
    $attended = strtotime('-3 days');
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    check(str_contains($say($owner, date('j', $attended) . ' de ' . $months[(int)date('n', $attended)] . ' del ' . date('Y', $attended)), '*1.* Ortopedia'), 'En el chat, una fecha escrita con el mes pasa a la pregunta de especialidad');
    $say($owner, 'menu');

    // --- Especialidad escrita con palabras en lugar del número ---
    foreach (['Ginecología', 'Ginecologia Y Obstetricia', 'Medicina general', 'Medicina interna', 'Pediatría'] as $name) $db->insert('specialties', ['name' => $name]);
    $intake = new App\WhatsAppIntake($app);
    $choose = new ReflectionMethod($intake, 'specialtyChoice');
    $ids = array_map(fn($r) => (int)$r['id'], $db->all('SELECT id FROM specialties WHERE active=1 ORDER BY name'));
    $position = fn(string $name) => array_search((int)$db->one('SELECT id FROM specialties WHERE name=?', [$name])['id'], $ids, true) + 1;
    check($choose->invoke($intake, 'No recuerdo', $ids) === 0 && $choose->invoke($intake, 'no sé', $ids) === 0 && $choose->invoke($intake, 'ninguna', $ids) === 0, '«No recuerdo», «no sé» o «ninguna» cuentan como especialidad desconocida');
    check($choose->invoke($intake, 'PEDIATRIA', $ids) === $position('Pediatría') && $choose->invoke($intake, 'fue en pediatría', $ids) === $position('Pediatría'), 'Reconoce la especialidad escrita, sin tildes ni mayúsculas y dentro de una frase');
    check($choose->invoke($intake, 'Ginecología', $ids) === $position('Ginecología') && $choose->invoke($intake, 'ginecologia y obstetricia', $ids) === $position('Ginecologia Y Obstetricia'), 'El nombre exacto gana cuando otro lo contiene');
    check($choose->invoke($intake, 'ortopedia', $ids) === $position('Ortopedia') && $choose->invoke($intake, '3', $ids) === 3 && $choose->invoke($intake, '99', $ids) === null && $choose->invoke($intake, 'cardio xyz', $ids) === null, 'Acepta el número y rechaza lo que no coincide');
    $db->run('DELETE FROM rate_limits');
    $say($owner, 'historia'); $say($owner, '1012345678'); $say($owner, '1'); $say($owner, date('d/m/Y', strtotime('-3 days')));
    $which = $say($owner, 'medicina');
    check(str_contains($which, '¿Cuál de estas?') && str_contains($which, 'Medicina general') && str_contains($which, 'Medicina interna') && !str_contains($which, 'Pediatría'), 'Si lo escrito coincide con varias, pregunta solo entre ellas');
    check(str_contains($say($owner, 'cardio xyz'), 'No reconocimos esa especialidad'), 'Un texto que no es una especialidad se vuelve a pedir con un mensaje claro');
    $before = (int)$db->one('SELECT MAX(id) n FROM requests')['n'];
    $say($owner, 'No recuerdo');
    $unknownSpecialty = $db->one('SELECT * FROM requests WHERE id>? ORDER BY id DESC LIMIT 1', [$before]);
    check($unknownSpecialty && $unknownSpecialty['specialty_id'] === null && $unknownSpecialty['status'] === 'queued', '«No recuerdo» en la especialidad radica la solicitud sin especialidad');
    echo "\nPASS: $checks comprobaciones. Sin envíos externos.\n";
} finally {
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories','outbox','wa-outbox','responses'] as $dir) { if (!is_dir($config['storage_path'] . '/' . $dir)) continue; foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
