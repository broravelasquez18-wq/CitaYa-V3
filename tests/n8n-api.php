<?php
declare(strict_types=1);
// Interfaz privada para n8n (SCRUM-39) y eventos hacia n8n, contra un QR y un n8n simulados. Sin WhatsApp real.
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
$config['public_url'] = 'https://portal.example.test';
$config['whatsapp_transport'] = 'qr';
$config['whatsapp_app_secret'] = bin2hex(random_bytes(16));
$config['n8n_secret'] = bin2hex(random_bytes(16));
$config['admin_whatsapp'] = '573000000999';
foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$mockDir = $config['storage_path'] . '/mock';
// /send simula el servicio QR (firma X-Signature); /events simula el webhook de eventos de n8n (firma X-CitaYa-Signature).
file_put_contents($mockDir . '/router.php', '<?php
$raw = file_get_contents("php://input");
$uri = $_SERVER["REQUEST_URI"];
if ($uri === "/health") { header("Content-Type: application/json"); echo json_encode(["connected" => !is_file(__DIR__ . "/down"), "pending" => 0]); return; }
$ok = $uri === "/send"
    ? hash_equals("sha256=" . hash_hmac("sha256", $raw, getenv("MOCK_QR")), $_SERVER["HTTP_X_SIGNATURE"] ?? "")
    : hash_equals("sha256=" . hash_hmac("sha256", $raw, getenv("MOCK_N8N")), $_SERVER["HTTP_X_CITAYA_SIGNATURE"] ?? "");
$status = $ok ? (int)(@file_get_contents(__DIR__ . "/status") ?: 200) : 401;
file_put_contents(__DIR__ . "/log", json_encode(["ok" => $ok, "uri" => $uri, "payload" => json_decode($raw, true)]) . "\n", FILE_APPEND);
http_response_code($status); header("Content-Type: application/json"); echo json_encode(["result" => $status === 200 ? "sent" : "error"]);');
$port = random_int(39000, 39900);
$config['whatsapp_qr_url'] = "http://127.0.0.1:$port";
$config['n8n_events_url'] = "http://127.0.0.1:$port/events";
putenv('MOCK_QR=' . $config['whatsapp_app_secret']);
putenv('MOCK_N8N=' . $config['n8n_secret']);
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $mockDir . '/router.php'], [1 => ['file', $mockDir . '/server.out', 'w'], 2 => ['file', $mockDir . '/server.out', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$db = new App\Database($config);
$app = new App\Application($db, $config);
$api = new App\N8nApi($app);
$automation = new App\Automation($app);
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function requests(string $uri): array { global $mockDir; return array_values(array_filter(is_file($mockDir . '/log') ? array_map(fn($l) => json_decode($l, true), file($mockDir . '/log', FILE_IGNORE_NEW_LINES)) : [], fn($r) => $r['uri'] === $uri)); }
function fails(callable $call, string $error): bool { try { $call(); return false; } catch (DomainException $e) { return $e->getMessage() === $error; } }
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $specialty = $db->insert('specialties', ['name' => 'Ortopedia']);
    $patient = $db->insert('patients', ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'Ana María Pérez', 'email' => '', 'active' => 1]);
    $encounter = $db->insert('encounters', ['patient_id' => $patient, 'specialty_id' => $specialty, 'attended_at' => date('Y-m-d 08:00:00', strtotime('-3 days')), 'admission' => 'ING-1', 'location' => 'Sede de prueba']);
    $package = $db->insert('packages', ['encounter_id' => $encounter, 'version' => 1, 'status' => 'available', 'published_at' => $app->now()]);
    $name = bin2hex(random_bytes(16)) . '.pdf'; $pdfPath = $config['storage_path'] . '/histories/' . $name;
    file_put_contents($pdfPath, "%PDF-1.4\nPDF ficticio\n%%EOF");
    $db->insert('clinical_files', ['package_id' => $package, 'storage_name' => $name, 'original_name' => 'doc.pdf', 'sort_order' => 1, 'size_bytes' => filesize($pdfPath), 'sha256' => hash_file('sha256', $pdfPath)]);
    $form = ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'ana maria perez', 'approximate_date' => date('Y-m-d', strtotime('-3 days')), 'specialty_id' => 0, 'email' => 'paciente@example.test'];
    $phone = '573001112233';

    // --- Firma ---
    $body = json_encode(['action' => 'requests.list', 'phone' => $phone, 'ts' => time()]);
    check(App\Automation::validSignature($body, App\Automation::sign($body, 'k'), 'k') && !App\Automation::validSignature($body, App\Automation::sign($body . ' ', 'k'), 'k') && !App\Automation::validSignature($body, App\Automation::sign($body, ''), ''), 'Firma HMAC: acepta la válida y rechaza alterada o sin secreto');
    check(fails(fn() => $api->handle(['action' => 'borrar_todo']), 'unknown_action') && fails(fn() => $api->handle(['action' => 'link.create', 'phone' => '12']), 'invalid_phone'), 'Rechaza acciones desconocidas y números inválidos');

    // --- Recepción y duplicados ---
    check($api->handle(['action' => 'inbound.register', 'phone' => $phone, 'message_id' => 'm1'])['new'] === true, 'Registra un mensaje nuevo');
    check($api->handle(['action' => 'inbound.register', 'phone' => $phone, 'message_id' => 'm1'])['new'] === false, 'Un reintento del mismo mensaje se reconoce como duplicado');

    // --- Plantillas y respuestas ---
    check(str_contains($api->handle(['action' => 'template.render', 'code' => 'menu'])['text'], 'Solicitar historia clínica') && fails(fn() => $api->handle(['action' => 'template.render', 'code' => 'history_found']), 'unknown_template'), 'Entrega el texto del menú editable y no otras plantillas');
    $sent = $api->handle(['action' => 'message.send', 'phone' => $phone, 'text' => 'Hola desde n8n', 'key' => 'm1-menu']);
    $last = requests('/send')[0] ?? null;
    check($sent['status'] === 'sent' && $last && $last['ok'] && $last['payload']['to'] === $phone && $last['payload']['body'] === 'Hola desde n8n', 'Envía la respuesta de n8n por el QR al chat que escribió');
    $again = $api->handle(['action' => 'message.send', 'phone' => $phone, 'text' => 'Hola desde n8n', 'key' => 'm1-menu']);
    check($again['job_id'] === $sent['job_id'] && count(requests('/send')) === 1, 'Repetir la misma clave no duplica el mensaje');
    check(fails(fn() => $api->handle(['action' => 'message.send', 'phone' => '573009999999', 'text' => 'x', 'key' => 'k1']), 'recipient_not_allowed'), 'No escribe a números que no han escrito al bot');
    check($api->handle(['action' => 'message.send', 'phone' => '573000000999', 'text' => 'Aviso', 'key' => 'admin-1'])['status'] === 'sent', 'Sí puede avisar al responsable (admin_whatsapp)');

    $first = $app->whatsapp()->queueText($phone, 'Primero', 'orden-1');
    $second = $app->whatsapp()->queueText($phone, 'Segundo', 'orden-2');
    $api->handle(['action' => 'delivery.send', 'job_id' => $second]);
    check($db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$second])['status'] === 'sent' && $db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$first])['status'] === 'queued', 'Enviar un mensaje concreto no envía otro de la cola');
    $api->handle(['action' => 'delivery.send', 'job_id' => $first]);

    // --- Enlace y solicitud ---
    $link = $api->handle(['action' => 'link.create', 'phone' => $phone]);
    preg_match('/wa=([a-f0-9]{48})$/', $link['url'], $m);
    check(isset($m[1]) && $link['hours'] === 6 && !$db->one('SELECT id FROM whatsapp_links WHERE token_hash=?', [$m[1]]), 'Crea el enlace personal de 6 h y guarda solo su hash');
    $found = $app->createRequest($form, 'ip-1', true, $app->whatsapp()->findLink($m[1]));
    check($app->request($found)['whatsapp_phone'] === $phone, 'La solicitud desde ese enlace queda vinculada al chat');
    $list = $api->handle(['action' => 'requests.list', 'phone' => $phone])['requests'];
    check(count($list) === 1 && $list[0]['reference'] === $app->request($found)['reference'] && $list[0]['label'] === 'En preparación' && $api->handle(['action' => 'requests.list', 'phone' => '573004445566'])['requests'] === [], 'La consulta de estado solo muestra las solicitudes de ese chat');

    // --- Entrega autorizada ---
    $pending = $api->handle(['action' => 'delivery.pending'])['jobs'];
    $history = array_values(array_filter($pending, fn($j) => $j['kind'] === 'history'))[0] ?? null;
    check($history && $history['reference'] === $app->request($found)['reference'], 'Lista la historia lista para salir con su radicado');
    $result = $api->handle(['action' => 'delivery.send', 'job_id' => $history['job_id']]);
    $doc = array_values(array_filter(requests('/send'), fn($r) => $r['payload']['type'] === 'document'))[0] ?? null;
    check($result['status'] === 'sent' && $result['progress'] === 2 && $doc && base64_decode($doc['payload']['data']) === file_get_contents($pdfPath), 'Envía la historia autorizada (texto y PDF íntegro) y registra el progreso');
    $count = count(requests('/send'));
    check($api->handle(['action' => 'delivery.send', 'job_id' => $history['job_id']])['status'] === 'sent' && count(requests('/send')) === $count, 'Pedir otra vez una entrega ya enviada no la repite');

    // --- Código CONFIRMAR ---
    $webId = $app->createRequest(['email' => 'web@example.test'] + $form, 'ip-2', true);
    $code = $app->whatsapp()->claimNumber($webId, '573001239999');
    check($api->handle(['action' => 'code.confirm', 'phone' => '573001239999', 'code' => 'ZZZZZZ'])['valid'] === false && $app->request($webId)['whatsapp_phone'] === null, 'Un código inválido no vincula nada');
    $confirmed = $api->handle(['action' => 'code.confirm', 'phone' => '573001239999', 'code' => strtolower($code)]);
    check($confirmed['valid'] && $confirmed['next'] === 'history' && $confirmed['reference'] === $app->request($webId)['reference'], 'Un código válido vincula el chat y encola la historia');

    // --- WhatsApp escrito en la web, sin confirmación ---
    $direct = $app->createRequest(['email' => 'web2@example.test'] + $form, 'ip-4', true, null, '573001238888');
    check($app->request($direct)['whatsapp_phone'] === '573001238888' && $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='history' AND phone='573001238888'", [$direct]), 'El WhatsApp escrito en la web queda vinculado y recibe la historia sin código');

    // --- Eventos hacia n8n ---
    $events = array_column($db->all('SELECT event FROM automation_events ORDER BY id'), 'event');
    check(in_array('historia.disponible', $events, true) && in_array('whatsapp.mensaje', $events, true), 'Se registran eventos de historia disponible y de mensajes');
    $missing = $app->createRequest(['document_number' => '99999999', 'email' => 'x@example.test'] + $form, 'ip-3', true, $app->whatsapp()->findLink(preg_replace('/.*wa=/', '', $api->handle(['action' => 'link.create', 'phone' => $phone])['url'])));
    $pendingEvent = $db->one("SELECT payload FROM automation_events WHERE event='solicitud.pendiente'");
    check($pendingEvent && json_decode($pendingEvent['payload'], true)['reference'] === $app->request($missing)['reference'] && !str_contains($pendingEvent['payload'], 'perez') && !str_contains($pendingEvent['payload'], '99999999'), 'El evento de solicitud pendiente lleva el radicado, sin nombre ni documento');
    while ($automation->tick());
    $delivered = requests('/events');
    check(count($delivered) === (int)$db->one('SELECT COUNT(*) n FROM automation_events')['n'] && array_product(array_column($delivered, 'ok')) === 1 && !$db->one("SELECT id FROM automation_events WHERE status<>'sent'"), 'Entrega todos los eventos a n8n con firma válida');
    file_put_contents($mockDir . '/status', '500');
    $db->run("UPDATE whatsapp_outbox SET status='queued',available_at=? WHERE kind='notice'", [$app->now()]);
    $notice = (int)$db->one("SELECT id FROM whatsapp_outbox WHERE kind='notice'")['id'];
    $api->handle(['action' => 'delivery.send', 'job_id' => $notice]);
    $automation->tick();
    check($db->one("SELECT status FROM automation_events WHERE event='envio.fallido'") !== null && $db->one("SELECT status FROM automation_events ORDER BY id DESC LIMIT 1")['status'] === 'retry', 'Un fallo de envío genera evento y, si n8n no responde, se reintenta');
    // --- Seguimiento (fase 6) ---
    @unlink($mockDir . '/status');
    $health = $api->handle(['action' => 'system.health']);
    check($health['worker_seconds'] === null && $health['qr'] === ['connected' => true, 'pending' => 0], 'Salud: sin latido del procesador y QR conectado');
    $db->run("INSERT INTO system_state (name,value,updated_at) VALUES ('worker_heartbeat','loop',?)", [date('Y-m-d H:i:s', time() - 20)]);
    touch($mockDir . '/down');
    $health = $api->handle(['action' => 'system.health']);
    check($health['worker_seconds'] >= 20 && $health['worker_seconds'] < 60 && $health['qr']['connected'] === false, 'Salud: latido reciente y QR desconectado');
    $db->run('UPDATE requests SET created_at=? WHERE id=?', [date('Y-m-d H:i:s', time() - 3600 * 30), $missing]);
    $late = $api->handle(['action' => 'requests.overdue']);
    check(count($late['requests']) === 1 && $late['requests'][0]['reference'] === $app->request($missing)['reference'] && $late['requests'][0]['stage'] === 'vencida', 'Plazos: detecta la solicitud pendiente vencida');
    $today = $api->handle(['action' => 'stats.summary', 'date' => date('Y-m-d')]);
    check($today['created'] === 3 && $today['from_whatsapp'] === 3 && $today['delivered'] >= 0 && $today['pending_now'] === 1 && $today['overdue_now'] === 1 && fails(fn() => $api->handle(['action' => 'stats.summary', 'date' => 'ayer']), 'invalid_date'), 'Resumen: cantidades del día y pendientes, sin datos personales');
    $disabled = $config; $disabled['n8n_events_url'] = '';
    (new App\Automation(new App\Application($db, $disabled)))->emit('prueba', [], 'x');
    check(!$db->one("SELECT id FROM automation_events WHERE event='prueba'"), 'Sin n8n configurado no se registran eventos');

    // --- Límite de mensajes por chat: una conversación guiada en curso no cuenta ---
    $db->run('DELETE FROM rate_limits');
    $guided = '573001114444';
    $db->run('INSERT INTO whatsapp_sessions (phone,state,data,updated_at) VALUES (?,?,?,?)', [$guided, 'intake_date', '{}', $app->now()]);
    $allowed = true;
    for ($i = 1; $i <= 70; $i++) $allowed = $allowed && $api->handle(['action' => 'inbound.register', 'phone' => $guided, 'message_id' => 'g' . $i])['allowed'];
    check($allowed, 'Durante una conversación guiada (solicitud o reporte) no se limitan los mensajes');
    $free = '573001115555';
    $results = [];
    for ($i = 1; $i <= 61; $i++) $results[] = $api->handle(['action' => 'inbound.register', 'phone' => $free, 'message_id' => 'f' . $i])['allowed'];
    check(!in_array(false, array_slice($results, 0, 60), true) && $results[60] === false, 'Fuera de una conversación, el límite es de 60 mensajes por hora');
    echo "\nPASS: $checks comprobaciones. Sin WhatsApp ni n8n reales.\n";
} finally {
    proc_terminate($process); proc_close($process);
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) { foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
