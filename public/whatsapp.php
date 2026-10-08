<?php
declare(strict_types=1);
// Webhook de WhatsApp Cloud API. Configúralo en Meta como <public_url>/whatsapp.php
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Content-Type: text/plain; charset=UTF-8');
try { $app = require dirname(__DIR__) . '/src/bootstrap.php'; }
catch (Throwable) { http_response_code(503); exit; }
$config = $app->config;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Verificación de la suscripción: PHP convierte hub.mode en hub_mode.
    $token = (string)($_GET['hub_verify_token'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $config['whatsapp_verify_token'] !== '' && hash_equals($config['whatsapp_verify_token'], $token)) {
        exit((string)($_GET['hub_challenge'] ?? ''));
    }
    http_response_code(403); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);
$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
// En modo local sin secreto se aceptan pruebas manuales; con Meta real la firma es obligatoria.
$unsignedLocal = $config['whatsapp_transport'] === 'local' && $config['whatsapp_app_secret'] === '';
if (!$unsignedLocal && !App\WhatsApp::validSignature($body, $signature, $config['whatsapp_app_secret'])) { http_response_code(401); exit; }
$payload = json_decode($body, true);
if (!is_array($payload)) { http_response_code(400); exit; }
try { $app->whatsapp()->receive($payload); }
catch (Throwable) { http_response_code(500); exit; } // Meta reintentará; los ids ya atendidos no se repiten.
echo 'OK';
