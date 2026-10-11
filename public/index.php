<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/UploadHttp.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
// PHP discards both POST and FILES when the whole request exceeds this limit.
if (App\UploadHttp::exceedsPostLimit($_SERVER, (string) ini_get('post_max_size'))) {
    http_response_code(413);
    $message = 'La carga supera el límite total del servidor (' . ini_get('post_max_size') . '). Divide los PDF en lotes más pequeños.';
    if (App\UploadHttp::wantsJson($_SERVER, $_POST)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message]);
    } else { header('Content-Type: text/plain; charset=UTF-8'); echo $message; }
    exit;
}
try { $app = require dirname(__DIR__) . '/src/bootstrap.php'; }
catch (Throwable) { http_response_code(503); exit('El servicio no está configurado. Ejecuta php bin/setup.php desde la carpeta del proyecto.'); }
session_save_path($app->config['storage_path'] . '/sessions');
session_name('citaya_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict', 'path' => '/']);
ini_set('session.use_strict_mode', '1');
session_start();
function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function uploadJson(): bool { return App\UploadHttp::wantsJson($_SERVER, $_POST); }
function go(string $page): never {
    if (uploadJson()) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['redirect' => '?page=' . rawurlencode($page)]); exit; }
    header('Location: ?page=' . rawurlencode($page)); exit;
}
function csrf(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function requireAdmin(): void { if (empty($_SESSION['admin_id'])) go('admin-login'); }
function requirePatient(): int {
    if (empty($_SESSION['request_access']) || $_SESSION['request_access'] !== ($_SESSION['request_id'] ?? null)) go('home');
    return (int) $_SESSION['request_access'];
}
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $app->config['session_seconds']) {
    if (!empty($_SESSION['request_id'])) $app->db->run("UPDATE requests SET status='expired' WHERE id=? AND status IN ('verified','matched','awaiting_selection','no_matches','unavailable') AND NOT (direct_search=1 AND status IN ('no_matches','unavailable'))", [$_SESSION['request_id']]); // Las solicitudes con radicado siguen esperando gestión.
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$page = (string) ($_GET['page'] ?? 'home');
$error = null;
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// Enlace personal enviado por el bot de WhatsApp. Viaja en el formulario como campo oculto.
$whatsappToken = (string) ($_POST['wa'] ?? $_GET['wa'] ?? '');
$whatsappLink = $whatsappToken === '' ? null : $app->whatsapp()->findLink($whatsappToken);
if ($whatsappToken !== '' && !$whatsappLink && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $flash = 'El enlace de WhatsApp venció o ya fue usado. Escribe HISTORIA al WhatsApp para recibir uno nuevo, o completa el formulario para recibir la historia solo por correo.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) throw new DomainException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
        switch ($_POST['action'] ?? '') {
            case 'request':
                // PRG plus a consumed form token protects against double submission.
                // WhatsApp escrito en la web (opcional): se valida y queda vinculado a la solicitud, sin confirmación desde el chat.
                $webPhone = !$whatsappLink && trim((string)($_POST['whatsapp'] ?? '')) !== '' ? App\WhatsApp::normalizePhone((string)$_POST['whatsapp']) : null;
                $id = $app->createRequest($_POST, $ip, true, $whatsappLink, $webPhone);
                $_SESSION['request_access'] = $id;
                $_SESSION['request_id'] = $id;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                go($app->request($id)['status'] === 'awaiting_selection' ? 'appointments' : 'status');
            case 'verify':
                if (empty($_SESSION['request_id']) || !$app->verify((int) $_SESSION['request_id'], trim((string) ($_POST['code'] ?? '')), $ip)) throw new DomainException('El código es incorrecto, venció o no está disponible.');
                session_regenerate_id(true);
                $_SESSION['request_access'] = $_SESSION['request_id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                go('appointments');
            case 'resend':
                if (empty($_SESSION['request_id'])) go('home');
                $app->resend((int) $_SESSION['request_id'], $ip);
                $_SESSION['flash'] = 'Si el registro está habilitado, recibirás un nuevo código.';
                go('verify');
            case 'filters':
                $app->changeFilters(requirePatient(), $_POST);
                // Fecha aproximada indicada después de la sugerencia: la solicitud queda con esa fecha y no se vuelve a sugerir.
                if (($_POST['from'] ?? '') === 'status') $_SESSION['date_given'] = requirePatient();
                // Búsqueda directa: si ya se encoló la historia o sigue sin resultados, se muestra el estado de la solicitud.
                go(in_array($app->request(requirePatient())['status'], ['verified','matched','awaiting_selection'], true) ? 'appointments' : 'status');
            case 'select-suggestion':
                $app->selectSuggestion(requirePatient(), (int) ($_POST['encounter_id'] ?? 0));
                go('status');
            case 'resend-history':
                $app->resendHistory(requirePatient(), (int) ($_POST['delivery_id'] ?? 0), $ip);
                $_SESSION['flash'] = 'Solicitaste el reenvío de tu historia al mismo correo. Estamos preparando el mensaje.';
                go('status');
            case 'confirm': $app->confirm(requirePatient(), (int) ($_POST['encounter_id'] ?? 0)); go('status');
            case 'new': unset($_SESSION['request_id'], $_SESSION['request_access']); go('home');
            case 'login':
                $app->limit('admin-login:' . $ip, 10, 900);
                $admin = $app->db->one('SELECT * FROM admins WHERE email=? AND active=1', [trim((string) ($_POST['email'] ?? ''))]);
                $valid = password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                if (!$admin || !$valid) throw new DomainException('Credenciales incorrectas.');
                session_regenerate_id(true); $_SESSION['admin_id'] = (int) $admin['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32)); go('admin');
            case 'logout': $_SESSION = []; session_regenerate_id(true); go('home');
            case 'reopen-case':
                requireAdmin();
                (new App\AdminDeliveries($app->db))->reopen((int)($_POST['request_id'] ?? 0), (int)$_SESSION['admin_id'], (int)($_POST['expected_job_id'] ?? 0));
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['flash'] = 'Caso reabierto. Está pendiente de gestión en Solicitudes. No se ha enviado otro correo.';
                go('deliveries');
            case 'assign-encounter':
                requireAdmin();
                $app->assignEncounter((int)($_POST['request_id'] ?? 0), (int)($_POST['encounter_id'] ?? 0), (int)$_SESSION['admin_id']);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['flash'] = 'Historia asignada. Se está enviando al paciente por correo' . ($app->request((int)$_POST['request_id'])['whatsapp_phone'] ? ' y WhatsApp.' : '.');
                go('deliveries');
            case 'respond-case':
                requireAdmin();
                $sources = [];
                foreach (($_FILES['files']['name'] ?? []) as $i => $name) {
                    $error = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                    if ($error === UPLOAD_ERR_NO_FILE) continue;
                    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['files']['tmp_name'][$i])) throw new DomainException('No se pudo recibir un adjunto. Revisa que no supere el límite de tamaño.');
                    $sources[] = ['path' => $_FILES['files']['tmp_name'][$i], 'name' => (string)$name];
                }
                $decision = (string)($_POST['decision'] ?? '');
                $app->respondCase((int)($_POST['request_id'] ?? 0), (int)$_SESSION['admin_id'], $decision, (string)($_POST['comment'] ?? ''), $sources);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['flash'] = $decision === 'approved' ? 'Respuesta «Aprobado» guardada. Se está enviando al paciente con los adjuntos.' : 'Respuesta «No Registra» guardada y enviada al paciente.';
                go('deliveries');
            case 'not-registered':
                requireAdmin();
                $app->markNotRegistered((int)($_POST['request_id'] ?? 0), (int)$_SESSION['admin_id']);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['flash'] = 'Respuesta «No registra» enviada. El paciente recibirá el aviso para verificar su documento.';
                go('deliveries');
            case 'request-upload':
                requireAdmin();
                $requestId = (int)($_POST['request_id'] ?? 0);
                $app->request($requestId);
                $uploads = [];
                foreach (($_FILES['pdfs']['name'] ?? []) as $i => $name) $uploads[] = ['name' => $name, 'tmp_name' => $_FILES['pdfs']['tmp_name'][$i], 'error' => $_FILES['pdfs']['error'][$i]];
                $packageId = (new App\Repository($app))->registerPackage($_POST, $uploads);
                $encounterId = (int)$app->db->one('SELECT encounter_id FROM packages WHERE id=?', [$packageId])['encounter_id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                if ($app->request($requestId)['status'] === 'queued') { $_SESSION['flash'] = 'Historia cargada. Coincidió con la solicitud y ya se está enviando al paciente.'; go('deliveries'); }
                $candidate = array_values(array_filter($app->candidateEncounters($requestId), fn($row) => (int)$row['id'] === $encounterId))[0] ?? null;
                if (!$candidate) { $_SESSION['flash'] = 'La historia se guardó en el repositorio, pero pertenece a otro número de documento. No se envió a esta solicitud.'; go('deliveries'); }
                if (!$candidate['name_matches']) { $_SESSION['flash'] = 'La historia se guardó, pero el nombre del PDF no coincide con el de la solicitud. No se envió; si corresponde, responde «No registra».'; go('deliveries'); }
                $app->assignEncounter($requestId, $encounterId, (int)$_SESSION['admin_id']);
                $_SESSION['flash'] = 'Historia cargada y asignada. Se está enviando al paciente.';
                go('deliveries');
            case 'save-template':
                requireAdmin();
                (new App\MessageTemplates($app))->save((string)($_POST['code'] ?? ''), (string)($_POST['body'] ?? ''), (int)$_SESSION['admin_id']);
                $_SESSION['flash'] = 'Plantilla guardada. Se usará en los próximos mensajes.';
                go('templates');
            case 'reset-template':
                requireAdmin();
                (new App\MessageTemplates($app))->reset((string)($_POST['code'] ?? ''), (int)$_SESSION['admin_id']);
                $_SESSION['flash'] = 'Se restauró el texto predeterminado de la plantilla.';
                go('templates');
            case 'upload':
                requireAdmin(); $_SESSION['upload_mode']='single'; $uploads = [];
                foreach (($_FILES['pdfs']['name'] ?? []) as $i => $name) $uploads[] = ['name' => $name, 'tmp_name' => $_FILES['pdfs']['tmp_name'][$i], 'error' => $_FILES['pdfs']['error'][$i]];
                $repository = new App\Repository($app);
                $_SESSION['single_upload_result'] = $repository->summary($repository->registerPackage($_POST, $uploads)) + ['received' => count($uploads)];
                $_SESSION['upload_result_pending'] = 'single';
                $_SESSION['flash'] = 'Historia completa registrada y disponible para solicitudes.'; go('admin');
            case 'bulk-upload':
                requireAdmin(); $_SESSION['upload_mode']='bulk'; $uploads=[];
                foreach (($_FILES['bulk_pdfs']['name'] ?? []) as $i=>$name) $uploads[]=['name'=>$name,'tmp_name'=>$_FILES['bulk_pdfs']['tmp_name'][$i],'error'=>$_FILES['bulk_pdfs']['error'][$i]];
                set_time_limit(180);
                $_SESSION['bulk_report']=(new App\BulkImport($app))->receive($_POST,$uploads);
                unset($_SESSION['single_upload_result']);
                $_SESSION['upload_result_pending'] = 'bulk';
                $_SESSION['csrf']=bin2hex(random_bytes(32));
                go('admin');
            case 'withdraw':
                requireAdmin();
                $app->db->transaction(function () use ($app) {
                    $packageId = (int) ($_POST['package_id'] ?? 0);
                    $app->db->run("UPDATE packages SET status='withdrawn' WHERE id=? AND status='available'", [$packageId]);
                    $app->event(null, 'package_withdrawn', 'package_' . $packageId);
                });
                $_SESSION['flash'] = 'Paquete retirado de nuevas entregas.'; go('admin');
            default: throw new DomainException('Acción no válida.');
        }
    } catch (DomainException $exception) { $error = $exception->getMessage(); }
    catch (Throwable) { $error = 'No pudimos completar la operación. Revisa los datos o inténtalo más tarde.'; http_response_code(500); }
}
if ($error !== null && uploadJson()) {
    if (http_response_code() < 400) http_response_code(422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $error]); exit;
}
if ($page === 'delivery' && ($_GET['fragment'] ?? '') === '1' && empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('La sesión administrativa venció. Inicia sesión nuevamente.');
}
if (in_array($page, ['admin','inbox','mail','deliveries','delivery','history','analytics','templates','whatsapp'], true)) requireAdmin();
$pendingCount = !empty($_SESSION['admin_id']) ? (new App\AdminDeliveries($app->db))->pendingCount() : 0;
$analytics = null;
if ($page === 'analytics') {
    $service = new App\AppointmentAnalytics($app->db);
    try { $analytics = $service->monthly((string)($_GET['month'] ?? '')); }
    catch (DomainException $exception) { $error = $exception->getMessage(); $analytics = $service->monthly(); }
    try { $activity = $service->activity((string)($_GET['activity_date'] ?? '')); }
    catch (DomainException $exception) { $error = $exception->getMessage(); $activity = $service->activity(); }
}
$deliverySearch = null; $deliveryDetail = null;
if (in_array($page, ['deliveries','delivery','history'], true)) {
    $adminDeliveries = new App\AdminDeliveries($app->db, (string)$app->config['app_key']);
    if ($page === 'delivery') {
        $deliveryDetail = $adminDeliveries->detail((int)($_GET['id'] ?? 0));
        if (!$deliveryDetail) http_response_code(404);
        elseif ($deliveryDetail['request']['awaiting']) $deliveryDetail['candidates'] = $app->candidateEncounters((int)$deliveryDetail['request']['id']);
    } else {
        $deliverySearch = $adminDeliveries->search((string)($_GET['q'] ?? ''), (int)($_GET['p'] ?? 1), $page==='history', $page === 'deliveries' && ($_GET['f'] ?? 'pending') !== 'all');
    }
}
if ($page === 'mail') {
    $job = $app->db->one("SELECT id FROM jobs WHERE id=? AND status='simulated'", [(int) ($_GET['id'] ?? 0)]);
    if (!$job || $app->config['mail_transport'] !== 'local') { http_response_code(404); exit; }
    $path = $app->config['storage_path'] . '/outbox/' . (int) $job['id'] . '.eml';
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: message/rfc822'); header('Content-Disposition: attachment; filename="correo-' . $job['id'] . '.eml"'); readfile($path); exit;
}
$specialties = $app->db->all('SELECT * FROM specialties WHERE active=1 ORDER BY name');
$request = null; $encounters = []; $historyResend = null; $recentSuggestion = null;
if ($page === 'verify' && empty($_SESSION['request_id'])) go('home');
if (in_array($page, ['appointments','status'], true)) {
    $id = requirePatient();
    try {
        $request = $app->request($id);
        if (!$request['direct_search'] || $page === 'appointments') $request=$app->requireVerified($id);
        if ($page === 'status') $historyResend = $app->historyResendInfo($id);
        if ($page === 'status') $recentSuggestion = $app->recentSuggestion($id);
        $dateGiven = ($_SESSION['date_given'] ?? null) === $id;
        if ($dateGiven) $recentSuggestion = null;
        if ($page === 'appointments') {
            if (!in_array($request['status'], ['verified','matched','awaiting_selection','no_matches','unavailable'], true)) go('status');
            $encounters = $app->search($id);
        }
    } catch (DomainException $e) { unset($_SESSION['request_access']); $_SESSION['flash'] = $e->getMessage(); go('home'); }
}
$steps = ['home' => 1, 'verify' => 2, 'appointments' => 2, 'status' => 3];
$labels = ['matched'=>'Datos coincidentes','no_matches'=>'Sin coincidencias','awaiting_selection'=>'Selecciona una atención','not_eligible'=>'Entrega no habilitada','pending_verification'=>'Pendiente de verificación','verified'=>'Verificada','queued'=>'En cola de envío','sending'=>'Preparando el envío','retry'=>'Reintento programado','sent'=>'Correo enviado','simulated'=>'Entrega simulada','failed'=>'No se pudo enviar','uncertain'=>'Resultado por confirmar','unavailable'=>'Historia no disponible','expired'=>'Solicitud vencida','cancelled'=>'Cancelado','waiting_window'=>'Esperando que el paciente escriba','not_registered'=>'No registra'];
if ($recentSuggestion && $request['approximate_date'] === null) $labels['no_matches'] = 'Consulta más reciente encontrada';
if (!empty($dateGiven)) $labels['no_matches'] = 'Solicitud registrada';
if ($page === 'delivery' && ($_GET['fragment'] ?? '') === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    $deliveryModal = true;
    require dirname(__DIR__) . '/templates/delivery.php';
    exit;
}
require dirname(__DIR__) . '/templates/layout.php';
