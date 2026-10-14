<?php
declare(strict_types=1);
// Transporte qr (tools/whatsapp-qr) contra un servicio simulado: sin WhatsApp real ni envíos externos.
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
foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$mockDir = $config['storage_path'] . '/mock';
// Servicio simulado: valida la firma como tools/whatsapp-qr y guarda cada petición.
file_put_contents($mockDir . '/router.php', '<?php
$raw = file_get_contents("php://input");
$ok = hash_equals("sha256=" . hash_hmac("sha256", $raw, getenv("MOCK_SECRET")), $_SERVER["HTTP_X_SIGNATURE"] ?? "");
$status = $ok ? (int)(@file_get_contents(__DIR__ . "/status") ?: 200) : 401;
file_put_contents(__DIR__ . "/log", json_encode(["ok" => $ok, "uri" => $_SERVER["REQUEST_URI"], "payload" => json_decode($raw, true)]) . "\n", FILE_APPEND);
http_response_code($status);
header("Content-Type: application/json");
echo json_encode(["result" => $status === 200 ? "sent" : ($status === 503 ? "whatsapp_disconnected" : "invalid_signature")]);');
$port = random_int(39000, 39900);
$config['whatsapp_qr_url'] = "http://127.0.0.1:$port";
putenv('MOCK_SECRET=' . $config['whatsapp_app_secret']);
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $mockDir . '/router.php'], [1 => ['file', $mockDir . '/server.out', 'w'], 2 => ['file', $mockDir . '/server.out', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$db = new App\Database($config);
$app = new App\Application($db, $config);
$bot = $app->whatsapp();
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function inbound(string $id, string $phone, string $text): array {
    return ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $phone, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
}
function requests(): array { global $mockDir; return is_file($mockDir . '/log') ? array_map(fn($l) => json_decode($l, true), file($mockDir . '/log', FILE_IGNORE_NEW_LINES)) : []; }
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $specialty = $db->insert('specialties', ['name' => 'Ortopedia']);
    $patient = $db->insert('patients', ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'Ana María Pérez', 'email' => '', 'active' => 1]);
    $encounter = $db->insert('encounters', ['patient_id' => $patient, 'specialty_id' => $specialty, 'attended_at' => date('Y-m-d 08:00:00', strtotime('-3 days')), 'admission' => 'ING-1', 'location' => 'Sede de prueba']);
    $package = $db->insert('packages', ['encounter_id' => $encounter, 'version' => 1, 'status' => 'available', 'published_at' => $app->now()]);
    $name = bin2hex(random_bytes(16)) . '.pdf'; $pdfPath = $config['storage_path'] . '/histories/' . $name;
    file_put_contents($pdfPath, "%PDF-1.4\nPDF ficticio\n%%EOF");
    $db->insert('clinical_files', ['package_id' => $package, 'storage_name' => $name, 'original_name' => 'doc.pdf', 'sort_order' => 1, 'size_bytes' => filesize($pdfPath), 'sha256' => hash_file('sha256', $pdfPath)]);

    $bot->receive(inbound('wamid.q1', '573001112233', 'hola'));
    check($bot->tick() && requests()[0]['ok'] && requests()[0]['uri'] === '/send', 'Envía al servicio QR con firma válida');
    check(requests()[0]['payload']['to'] === '573001112233' && requests()[0]['payload']['type'] === 'text' && str_contains(requests()[0]['payload']['body'], 'Solicitar historia clínica'), 'El menú llega como texto al número del remitente');
    check($db->one("SELECT status FROM whatsapp_outbox ORDER BY id LIMIT 1")['status'] === 'sent', 'El mensaje queda como enviado');

    $bot->receive(inbound('wamid.q2', '573001112233', 'historia'));
    $bot->tick();
    preg_match('/wa=([a-f0-9]{48})/', array_slice(requests(), -1)[0]['payload']['body'], $m);
    $found = $app->createRequest(['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'ana maria perez', 'approximate_date' => date('Y-m-d', strtotime('-3 days')), 'specialty_id' => 0, 'email' => 'paciente@example.test'], 'ip-1', true, $bot->findLink($m[1]));
    $before = count(requests());
    while ($bot->tick());
    $sent = array_slice(requests(), $before);
    $doc = array_values(array_filter($sent, fn($r) => $r['payload']['type'] === 'document'))[0] ?? null;
    check($doc && base64_decode($doc['payload']['data']) === file_get_contents($pdfPath) && str_starts_with($doc['payload']['filename'], 'Historia_HC-'), 'La historia llega como PDF íntegro');
    check($db->one("SELECT status FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$found])['status'] === 'sent', 'El envío de la historia queda como enviado');

    file_put_contents($mockDir . '/status', '503');
    $bot->receive(inbound('wamid.q3', '573001112233', 'hola'));
    $bot->tick();
    check($db->one("SELECT status,last_error FROM whatsapp_outbox ORDER BY id DESC LIMIT 1") === ['status' => 'retry', 'last_error' => 'qr_whatsapp_disconnected'], 'Con el teléfono desconectado el mensaje queda en reintento');

    $wrong = $config; $wrong['whatsapp_app_secret'] = 'otro-secreto';
    $db->run("UPDATE whatsapp_outbox SET status='queued',available_at=? WHERE status='retry'", [$app->now()]);
    @unlink($mockDir . '/status');
    (new App\Application($db, $wrong))->whatsapp()->tick();
    check(array_slice(requests(), -1)[0]['ok'] === false && $db->one("SELECT status FROM whatsapp_outbox ORDER BY id DESC LIMIT 1")['status'] === 'failed', 'Una firma con otro secreto es rechazada');

    $noSecret = $config; $noSecret['whatsapp_app_secret'] = '';
    $bot->receive(inbound('wamid.q4', '573001112233', 'hola'));
    (new App\Application($db, $noSecret))->whatsapp()->tick();
    check($db->one("SELECT last_error FROM whatsapp_outbox ORDER BY id DESC LIMIT 1")['last_error'] === 'whatsapp_not_configured', 'Sin secreto compartido no se envía nada');
    echo "\nPASS: $checks comprobaciones. Sin WhatsApp real.\n";
} finally {
    proc_terminate($process); proc_close($process);
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) { foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
