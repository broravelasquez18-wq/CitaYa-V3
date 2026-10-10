<?php
declare(strict_types=1);
// Interfaz privada para n8n. Petición POST JSON con "action" y "ts" (segundos Unix), firmada en la cabecera
// X-CitaYa-Signature: sha256=<HMAC-SHA256 del cuerpo con n8n_secret>. Ver src/N8nApi.php.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=UTF-8');
try { $app = require dirname(__DIR__) . '/src/bootstrap.php'; }
catch (Throwable) { http_response_code(503); exit('{"error":"unavailable"}'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"error":"method_not_allowed"}'); }
$body = (string)file_get_contents('php://input', false, null, 0, 64 * 1024);
if (!App\Automation::validSignature($body, (string)($_SERVER['HTTP_X_CITAYA_SIGNATURE'] ?? ''), (string)($app->config['n8n_secret'] ?? ''))) {
    http_response_code(401); exit('{"error":"invalid_signature"}');
}
$payload = json_decode($body, true);
// La marca de tiempo impide reutilizar una petición firmada capturada más tarde.
if (!is_array($payload) || abs(time() - (int)($payload['ts'] ?? 0)) > 300) { http_response_code(400); exit('{"error":"invalid_request"}'); }
try {
    echo json_encode(['ok' => true] + (new App\N8nApi($app))->handle($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (DomainException $error) {
    http_response_code(422); echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable) {
    http_response_code(500); echo '{"ok":false,"error":"internal_error"}';
}
