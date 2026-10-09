<?php
declare(strict_types=1);
// Genera config/local.php a partir de variables de entorno (Dokploy → Environment).
// Se ejecuta en cada arranque del contenedor, antes de bin/setup.php.

$root = dirname(__DIR__);
$env = static fn(string $k, string $d = ''): string => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;

$host = $env('DB_HOST', 'db');
$port = $env('DB_PORT', '3306');
$name = $env('DB_NAME', 'citaya');

// app_key estable: variable de entorno o archivo persistente en el volumen de storage.
$appKey = $env('APP_KEY');
$storage = $root . '/storage';
if (!is_dir($storage)) mkdir($storage, 0775, true);
$keyFile = $storage . '/app_key';
if ($appKey === '') {
    $appKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
    if ($appKey === '') { $appKey = bin2hex(random_bytes(32)); file_put_contents($keyFile, $appKey); }
}

$settings = [
    'mode'            => $env('APP_MODE', 'demo'),
    'db_dsn'          => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
    'db_user'         => $env('DB_USER', 'citaya'),
    'db_password'     => $env('DB_PASS'),
    'app_key'         => $appKey,
    'mail_transport'  => $env('MAIL_TRANSPORT', 'local'),
    'test_recipient'  => $env('MAIL_TEST_RECIPIENT', $env('MAIL_USER')),
    'smtp_host'       => $env('MAIL_HOST'),
    'smtp_port'       => (int) $env('MAIL_PORT', '587'),
    'smtp_encryption' => $env('MAIL_ENCRYPTION', 'tls'),
    'smtp_user'       => $env('MAIL_USER'),
    'smtp_password'   => $env('MAIL_PASSWORD'),
    'mail_from'       => $env('MAIL_FROM', $env('MAIL_USER', 'historias@example.test')),
    'mail_from_name'  => $env('MAIL_FROM_NAME', 'CitaYa · Historias clínicas'),
    // WhatsApp: local (sin envíos) | zernio | cloud. Zernio necesita además la URL pública HTTPS.
    'whatsapp_transport'    => $env('WHATSAPP_TRANSPORT', 'local'),
    'zernio_api_key'        => $env('ZERNIO_API_KEY'),
    'zernio_account_id'     => $env('ZERNIO_ACCOUNT_ID'),
    'zernio_webhook_secret' => $env('ZERNIO_WEBHOOK_SECRET'),
    'whatsapp_template_ready' => $env('WHATSAPP_TEMPLATE_READY'),
    'whatsapp_template_lang'  => $env('WHATSAPP_TEMPLATE_LANG', 'es'),
    'public_url'      => $env('PUBLIC_URL', 'http://localhost'),
];

file_put_contents($root . '/config/local.php', "<?php\nreturn " . var_export($settings, true) . ";\n");
echo "config/local.php generado (db_host=$host, db_name=$name, transporte={$settings['mail_transport']}).\n";
