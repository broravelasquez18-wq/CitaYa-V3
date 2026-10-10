<?php
declare(strict_types=1);

return [
    'mode' => 'demo',
    'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=citaya_historias;charset=utf8mb4',
    'db_user' => 'root',
    'db_password' => '',
    'storage_path' => dirname(__DIR__) . '/storage',
    'app_key' => '',
    'mail_transport' => 'local', // local: private preview; smtp: external delivery
    'test_recipient' => 'broravelasquez18@gmail.com',
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_user' => '',
    'smtp_password' => '',
    'mail_from' => 'historias@example.test',
    'mail_from_name' => 'CitaYa · Historias clínicas',
    'search_days' => 30,
    'otp_seconds' => 600,
    'otp_attempts' => 5,
    'resend_seconds' => 60,
    'session_seconds' => 900,
    'max_mail_bytes' => 20 * 1024 * 1024,
    'max_upload_bytes' => 12 * 1024 * 1024,
    'mail_attempts' => 3,
    'history_resend_seconds' => 60,
    'history_resend_max' => 3,
    // WhatsApp Cloud API (Meta). local: guarda los mensajes en storage/wa-outbox sin enviarlos.
    'whatsapp_transport' => 'local', // local | cloud | zernio | qr (pruebas con número secundario vía tools/whatsapp-qr)
    'whatsapp_qr_url' => 'http://127.0.0.1:3218',
    'whatsapp_api_version' => 'v21.0',
    'whatsapp_phone_number_id' => '',
    'whatsapp_token' => '',
    'whatsapp_verify_token' => '',
    'whatsapp_app_secret' => '',
    // Zernio (https://docs.zernio.com): API unificada sobre la Cloud API de Meta. Ver bin/zernio.php.
    'zernio_api_url' => 'https://zernio.com/api',
    'zernio_api_key' => '',
    'zernio_account_id' => '',
    'zernio_webhook_secret' => '',
    'whatsapp_link_seconds' => 6 * 3600,
    // Días que una solicitud sin historia espera a que se cargue el PDF para enviarse sola.
    'whatsapp_pending_days' => 30,
    // Días que un mensaje espera (waiting_window) a que el paciente escriba de nuevo con la ventana de 24 h cerrada.
    'whatsapp_window_wait_days' => 7,
    // Plantilla aprobada por Meta (opcional) con una variable {{1}} = radicado, para avisar con la ventana cerrada.
    'whatsapp_template_ready' => '',
    'whatsapp_template_lang' => 'es',
    // Tiempo estimado que se informa al paciente cuando su solicitud queda con radicado.
    'whatsapp_estimated_hours' => 24,
    // n8n (épica SCRUM-37): secreto compartido para /n8n.php y los eventos, y webhook que recibe los eventos.
    'n8n_secret' => '',
    'n8n_events_url' => '',
    // Quién procesa la cola de WhatsApp: worker (bin/worker.php) o n8n (lo pide por /n8n.php). Un solo responsable.
    'whatsapp_dispatch' => 'worker',
    // WhatsApp del responsable de las excepciones (solo dígitos, con indicativo); n8n puede escribirle sin que haya escrito antes.
    'admin_whatsapp' => '',
    // Número del bot (solo dígitos, con indicativo) para el botón «Confirmar por WhatsApp» del formulario web.
    'whatsapp_bot_number' => '',
    // URL pública del portal (por ejemplo, el túnel HTTPS hacia public/).
    'public_url' => 'http://localhost/CitaYaV3/public',
];
