<?php
declare(strict_types=1);
// Transporte zernio contra un servidor simulado de la API de Zernio: sin WhatsApp real ni envíos externos.
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
$config['whatsapp_transport'] = 'zernio';
$config['zernio_api_key'] = 'sk_test_' . bin2hex(random_bytes(8));
$config['zernio_account_id'] = 'acc_citaya';
$config['zernio_webhook_secret'] = bin2hex(random_bytes(24));
$config['whatsapp_template_ready'] = '';
foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$mockDir = $config['storage_path'] . '/mock';
// API simulada: exige el token, guarda cada petición (JSON o multipart) y responde según el archivo «status».
file_put_contents($mockDir . '/router.php', '<?php
$auth = ($_SERVER["HTTP_AUTHORIZATION"] ?? "") === "Bearer " . getenv("MOCK_KEY");
$json = str_starts_with($_SERVER["CONTENT_TYPE"] ?? "", "application/json");
$file = $_FILES["attachment"] ?? null;
file_put_contents(__DIR__ . "/log", json_encode([
    "auth" => $auth, "method" => $_SERVER["REQUEST_METHOD"], "uri" => $_SERVER["REQUEST_URI"], "key" => $_SERVER["HTTP_IDEMPOTENCY_KEY"] ?? null,
    "fields" => $json ? json_decode(file_get_contents("php://input"), true) : $_POST,
    "file" => $file ? ["name" => $file["name"], "type" => $file["type"], "sha256" => hash_file("sha256", $file["tmp_name"])] : null,
]) . "\n", FILE_APPEND);
[$status, $body] = $auth ? (json_decode((string)@file_get_contents(__DIR__ . "/status"), true) ?: [200, ["success" => true, "data" => ["messageId" => "wamid.out"]]]) : [401, ["error" => "Unauthorized", "code" => "unauthorized"]];
http_response_code($status);
header("Content-Type: application/json");
echo json_encode($body);');
$port = random_int(39000, 39900);
$config['zernio_api_url'] = "http://127.0.0.1:$port/api";
putenv('MOCK_KEY=' . $config['zernio_api_key']);
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $mockDir . '/router.php'], [1 => ['file', $mockDir . '/server.out', 'w'], 2 => ['file', $mockDir . '/server.out', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$db = new App\Database($config);
$app = new App\Application($db, $config);
$bot = $app->whatsapp();
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function event(string $id, string $phone, string $text, array $override = []): array {
    return array_replace_recursive(['id' => 'evt_' . $id, 'event' => 'message.received', 'timestamp' => date('c'),
        'message' => ['id' => 'msg_' . $id, 'conversationId' => 'conv_' . $phone, 'platform' => 'whatsapp', 'platformMessageId' => 'wamid.' . $id,
            'direction' => 'incoming', 'text' => $text, 'attachments' => [], 'sender' => ['id' => $phone, 'phoneNumber' => '+' . $phone], 'sentAt' => date('c'), 'isRead' => false],
        'conversation' => ['id' => 'conv_' . $phone, 'platformConversationId' => $phone, 'status' => 'active'],
        'account' => ['accountId' => 'acc_citaya', 'profileId' => 'prof_1'], 'metadata' => null], $override);
}
function requests(): array { global $mockDir; return is_file($mockDir . '/log') ? array_map(fn($l) => json_decode($l, true), file($mockDir . '/log', FILE_IGNORE_NEW_LINES)) : []; }
function last(): array { return array_slice(requests(), -1)[0] ?? []; }
function respond(?int $status, array $body = []): void { global $mockDir; $status === null ? @unlink($mockDir . '/status') : file_put_contents($mockDir . '/status', json_encode([$status, $body])); }
function lastOutbox(): array { global $db; return $db->one('SELECT * FROM whatsapp_outbox ORDER BY id DESC LIMIT 1'); }
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $specialty = $db->insert('specialties', ['name' => 'Ortopedia']);
    $patient = $db->insert('patients', ['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'Ana María Pérez', 'email' => '', 'active' => 1]);
    $encounter = $db->insert('encounters', ['patient_id' => $patient, 'specialty_id' => $specialty, 'attended_at' => date('Y-m-d 08:00:00', strtotime('-3 days')), 'admission' => 'ING-1', 'location' => 'Sede de prueba']);
    $package = $db->insert('packages', ['encounter_id' => $encounter, 'version' => 1, 'status' => 'available', 'published_at' => $app->now()]);
    $name = bin2hex(random_bytes(16)) . '.pdf'; $pdfPath = $config['storage_path'] . '/histories/' . $name;
    file_put_contents($pdfPath, "%PDF-1.4\nPDF ficticio\n%%EOF");
    $db->insert('clinical_files', ['package_id' => $package, 'storage_name' => $name, 'original_name' => 'doc.pdf', 'sort_order' => 1, 'size_bytes' => filesize($pdfPath), 'sha256' => hash_file('sha256', $pdfPath)]);

    // --- Firma del webhook ---
    $body = json_encode(event('s1', '573001112233', 'hola'));
    check(App\WhatsApp::validZernioSignature($body, hash_hmac('sha256', $body, 'secreto'), 'secreto'), 'Acepta la firma X-Zernio-Signature válida');
    check(!App\WhatsApp::validZernioSignature($body, hash_hmac('sha256', $body . ' ', 'secreto'), 'secreto') && !App\WhatsApp::validZernioSignature($body, hash_hmac('sha256', $body, ''), ''), 'Rechaza cuerpo alterado o secreto vacío');

    // --- Entrada ---
    check($bot->receiveZernio(event('1', '573001112233', 'Hola buenas')) === 1, 'Un mensaje entrante de WhatsApp se atiende');
    check($db->one('SELECT conversation_id FROM whatsapp_conversations WHERE phone=?', ['573001112233'])['conversation_id'] === 'conv_573001112233', 'Se guarda la conversación de Zernio del número');
    check($bot->receiveZernio(event('1', '573001112233', 'Hola buenas')) === 0, 'Un reintento del webhook no duplica la respuesta');
    $ignored = [
        event('x1', '573001112233', 'hola', ['account' => ['accountId' => 'acc_otro']]),
        event('x2', '573001112233', 'hola', ['message' => ['direction' => 'outgoing']]),
        event('x3', '573001112233', 'hola', ['message' => ['platform' => 'instagram']]),
        event('x4', '573001112233', 'hola', ['event' => 'message.delivered']),
        event('x5', '573001112233', 'hola', ['message' => ['sender' => ['id' => 'CO.bsuid123', 'phoneNumber' => null]]]),
        ['id' => 'evt_t', 'event' => 'webhook.test', 'message' => 'Test', 'timestamp' => date('c')],
    ];
    check(array_sum(array_map(fn($e) => $bot->receiveZernio($e), $ignored)) === 0, 'Ignora otra cuenta, salientes, otras redes, estados, usuarios sin teléfono y la prueba de webhook');

    // --- Envío de texto ---
    check($bot->tick() && last()['auth'] && last()['method'] === 'POST' && last()['uri'] === '/api/v1/inbox/conversations/conv_573001112233/messages', 'Responde en la conversación con el token de la API');
    check(last()['fields']['accountId'] === 'acc_citaya' && str_contains(last()['fields']['message'], 'Solicitar historia clínica') && str_starts_with((string)last()['key'], 'citaya-'), 'El menú sale como texto con la cuenta y clave de idempotencia');
    check(lastOutbox()['status'] === 'sent', 'El mensaje queda como enviado');

    // --- Historia completa ---
    $bot->receiveZernio(event('2', '573001112233', 'necesito mi historia'));
    $bot->tick();
    preg_match('/wa=([a-f0-9]{48})/', last()['fields']['message'], $m);
    check(isset($m[1]), 'La solicitud de historia recibe el enlace del formulario');
    $found = $app->createRequest(['document_type' => 'CC', 'document_number' => '1012345678', 'full_name' => 'ana maria perez', 'approximate_date' => date('Y-m-d', strtotime('-3 days')), 'specialty_id' => 0, 'email' => 'paciente@example.test'], 'ip-1', true, $bot->findLink($m[1]));
    $before = count(requests());
    while ($bot->tick());
    $sent = array_slice(requests(), $before);
    $doc = array_values(array_filter($sent, fn($r) => $r['file'] !== null))[0] ?? null;
    check($doc && $doc['file']['sha256'] === hash_file('sha256', $pdfPath) && $doc['file']['type'] === 'application/pdf' && str_starts_with($doc['file']['name'], 'Historia_HC-'), 'La historia llega como PDF íntegro con su nombre');
    check($doc['fields']['accountId'] === 'acc_citaya' && $doc['fields']['message'] === 'Documento 1 de 1', 'El PDF lleva la cuenta y su leyenda');
    check(count(array_unique(array_column($sent, 'key'))) === count($sent), 'Cada mensaje del envío usa su propia clave de idempotencia');
    check($db->one("SELECT status FROM whatsapp_outbox WHERE request_id=? AND kind='history'", [$found])['status'] === 'sent', 'El envío de la historia queda como enviado');

    // --- Errores ---
    respond(500, ['error' => 'Platform error', 'code' => 'platform_api_error', 'platform' => 'whatsapp', 'platformError' => ['code' => 131000]]);
    $bot->receiveZernio(event('3', '573001112233', 'hola'));
    $bot->tick();
    check(lastOutbox()['status'] === 'retry' && lastOutbox()['last_error'] === 'zernio_500_platform_api_error_131000', 'Una falla temporal queda en reintento');
    respond(400, ['error' => 'Bad request', 'code' => 'PLATFORM_LIMITATION']);
    $db->run("UPDATE whatsapp_outbox SET available_at=? WHERE status='retry'", [$app->now()]);
    $bot->tick();
    check(lastOutbox()['status'] === 'failed' && lastOutbox()['encrypted_body'] === null, 'Un rechazo definitivo falla sin reintentar y borra el texto cifrado');

    // --- Ventana de 24 h ---
    respond(403, ['error' => 'Outside window', 'code' => 'platform_api_error', 'platform' => 'whatsapp', 'platformError' => ['code' => 131047]]);
    $templated = $config; $templated['whatsapp_template_ready'] = 'historia_lista';
    $templateBot = (new App\Application($db, $templated))->whatsapp();
    $db->insert('whatsapp_outbox', ['request_id' => $found, 'phone' => '573001112233', 'kind' => 'notice', 'encrypted_body' => App\Crypto::seal('Tu radicado', $config['app_key']), 'available_at' => $app->now(), 'created_at' => $app->now(), 'dedupe_key' => 'test:window']);
    $templateBot->tick();
    $template = last();
    check(lastOutbox()['status'] === 'waiting_window', 'Con la ventana cerrada el mensaje espera');
    check($template['uri'] === '/api/v1/inbox/conversations' && $template['fields']['templateName'] === 'historia_lista' && $template['fields']['participantId'] === '573001112233' && $template['fields']['templateParams'] === [$app->request($found)['reference']], 'Se avisa con la plantilla aprobada y el radicado');
    respond(null);
    $bot->receiveZernio(event('4', '573001112233', 'hola'));
    check($db->one("SELECT status FROM whatsapp_outbox WHERE dedupe_key='test:window'")['status'] === 'queued', 'Cuando el paciente escribe, el mensaje en espera vuelve a la cola');

    // --- Configuración ---
    $db->insert('whatsapp_outbox', ['phone' => '573009990000', 'kind' => 'text', 'encrypted_body' => App\Crypto::seal('Hola', $config['app_key']), 'available_at' => $app->now(), 'created_at' => $app->now(), 'dedupe_key' => 'test:noconv']);
    while ($bot->tick());
    check($db->one("SELECT last_error FROM whatsapp_outbox WHERE dedupe_key='test:noconv'")['last_error'] === 'zernio_no_conversation', 'Sin conversación previa del número no se envía texto libre');
    $wrongKey = $config; $wrongKey['zernio_api_key'] = 'sk_otro';
    $bot->receiveZernio(event('5', '573001112233', 'hola'));
    (new App\Application($db, $wrongKey))->whatsapp()->tick();
    check(last()['auth'] === false && lastOutbox()['status'] === 'failed' && lastOutbox()['last_error'] === 'zernio_401_unauthorized', 'Un token inválido falla sin reintentar');
    $noAccount = $config; $noAccount['zernio_account_id'] = '';
    $bot->receiveZernio(event('6', '573001112233', 'hola'));
    $count = count(requests());
    (new App\Application($db, $noAccount))->whatsapp()->tick();
    check(count(requests()) === $count && lastOutbox()['last_error'] === 'whatsapp_not_configured', 'Sin cuenta configurada no se envía nada');
    echo "\nPASS: $checks comprobaciones. Sin WhatsApp real.\n";
} finally {
    proc_terminate($process); proc_close($process);
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories', 'outbox', 'wa-outbox', 'mock'] as $dir) { foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
