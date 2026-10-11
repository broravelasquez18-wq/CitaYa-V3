<?php
declare(strict_types=1);
// Comprueba el inicio de sesión SMTP (sin mostrar la contraseña) y, con --retry, vuelve a encolar los correos
// que fallaron por credenciales o por red en los últimos 7 días.
// Uso (terminal del contenedor app): php bin/mail-check.php   |   php bin/mail-check.php --retry
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $app->config;
echo "Transporte: {$config['mail_transport']} · {$config['smtp_host']}:{$config['smtp_port']} ({$config['smtp_encryption']}) · usuario {$config['smtp_user']}\n";
if ($config['mail_transport'] !== 'smtp') exit("El transporte no es SMTP: no hay nada que comprobar.\n");
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->isSMTP();
$mail->Host = $config['smtp_host'];
$mail->Port = (int)$config['smtp_port'];
$mail->SMTPAuth = true;
$mail->Username = $config['smtp_user'];
$mail->Password = $config['smtp_password'];
$mail->SMTPSecure = $config['smtp_encryption'];
$mail->Timeout = 20;
try {
    $mail->smtpConnect();
    $mail->smtpClose();
    echo "Inicio de sesión SMTP correcto.\n";
} catch (Throwable $error) {
    echo 'No se pudo iniciar sesión: ' . $error->getMessage() . "\n";
    if (str_contains($error->getMessage(), 'authenticate') && str_contains((string)$config['smtp_host'], 'gmail')) {
        echo "Gmail exige una contraseña de aplicación (16 caracteres): myaccount.google.com/apppasswords, con la verificación en dos pasos activa.\n";
    }
    exit(1);
}
if (in_array('--retry', $argv, true)) {
    // Las historias se arman de nuevo desde el paquete; las respuestas recuperan su texto del comentario cifrado
    // de la solicitud (el correo fallido lo borró). Los avisos antiguos sin comentario no se pueden reconstruir.
    $jobs = $app->db->all("SELECT j.id,j.request_id,j.kind,r.response_note FROM jobs j JOIN requests r ON r.id=j.request_id
        WHERE j.status='failed' AND (j.last_error='smtp_auth_failed' OR j.last_error LIKE 'transport_error_%') AND j.created_at>?
        AND (j.kind='history' OR (j.kind IN ('response','notice') AND j.dedupe_key LIKE 'response:%' AND r.response_note IS NOT NULL))", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    foreach ($jobs as $job) {
        $app->db->run("UPDATE jobs SET status='queued',attempts=0,last_error=NULL,available_at=?,encrypted_payload=? WHERE id=?",
            [$app->now(), $job['kind'] === 'history' ? null : $job['response_note'], $job['id']]);
        if (in_array($job['kind'], ['history', 'response'], true)) $app->db->run("UPDATE requests SET status='queued' WHERE id=? AND status='failed'", [$job['request_id']]);
    }
    echo count($jobs) . " correo(s) vuelven a la cola; el procesador los enviará en el próximo minuto.\n";
}
