<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('America/Bogota');
$root = dirname(__DIR__);
$localPath = $root . '/config/local.php';
$config = require $root . '/config/default.php';
if (is_file($localPath)) $config = array_replace($config, require $localPath);
foreach (['/histories','/outbox','/sessions','/wa-outbox','/responses'] as $directory) {
    if (!is_dir($config['storage_path'] . $directory)) mkdir($config['storage_path'] . $directory, 0700, true);
}
file_put_contents($config['storage_path'] . '/.htaccess', "Require all denied\n");
if (!$config['app_key']) {
    $settings = is_file($localPath) ? require $localPath : [];
    $settings['app_key'] = bin2hex(random_bytes(32));
    file_put_contents($localPath, "<?php\nreturn " . var_export($settings, true) . ";\n");
    $config['app_key'] = $settings['app_key'];
}
$serverDsn = preg_replace('/;dbname=[^;]+/', '', $config['db_dsn']);
preg_match('/dbname=([a-zA-Z0-9_]+)/', $config['db_dsn'], $match);
if (!$match) throw new RuntimeException('La configuración debe indicar el nombre de la base de datos.');
try {
    $server = new PDO($serverDsn, $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $match[1] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (\PDOException $e) {
    // La base puede existir ya y el usuario carecer del privilegio global CREATE
    // (habitual en contenedores donde la base se crea con la imagen). Se continúa
    // conectando directamente; si de verdad no existe, la siguiente conexión falla.
}
$app = new App\Application(new App\Database($config), $config);
$db = $app->db;
$db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
if ($db->one("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='approximate_date'")['IS_NULLABLE'] === 'NO') {
    $db->pdo->exec('ALTER TABLE requests MODIFY approximate_date DATE NULL');
}
foreach (['case_reopened_at'=>'DATETIME NULL','case_reopened_by'=>'BIGINT UNSIGNED NULL','case_reopened_after_job_id'=>'BIGINT UNSIGNED NULL','direct_search'=>'TINYINT NOT NULL DEFAULT 0','matched_at'=>'DATETIME NULL','claimed_document_type'=>'VARCHAR(8) NULL','claimed_document_number'=>'VARCHAR(24) NULL','whatsapp_phone'=>'VARCHAR(20) NULL','whatsapp_claimed'=>'VARCHAR(20) NULL','whatsapp_code_hash'=>'CHAR(64) NULL','whatsapp_code_expires'=>'DATETIME NULL','resolved_by'=>'BIGINT UNSIGNED NULL','resolved_at'=>'DATETIME NULL','resolution'=>'VARCHAR(20) NULL','response_note'=>'TEXT NULL'] as $column=>$definition) {
    if (!$db->one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'requests\' AND COLUMN_NAME=?',[$column])) $db->pdo->exec("ALTER TABLE requests ADD COLUMN $column $definition");
}
// Roles del acceso interno (SCRUM-46): los administradores existentes quedan como «admin».
foreach (['role' => "VARCHAR(16) NOT NULL DEFAULT 'admin'", 'phone' => 'VARCHAR(20) NULL', 'created_at' => 'DATETIME NULL', 'document_number' => 'VARCHAR(20) NULL UNIQUE', 'deleted_at' => 'DATETIME NULL'] as $column => $definition) {
    if (!$db->one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME=?", [$column])) $db->pdo->exec("ALTER TABLE admins ADD $column $definition");
}
// Detalle de cada orden (lo que se ordenó, vigencia) para las indicaciones del bot.
if (!$db->one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clinical_orders' AND COLUMN_NAME='details'")) $db->pdo->exec('ALTER TABLE clinical_orders ADD details TEXT NULL');
if (!$db->one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clinical_files' AND COLUMN_NAME='page_count'")) {
    $db->pdo->exec('ALTER TABLE clinical_files ADD COLUMN page_count INT NULL AFTER size_bytes');
}
if (!$db->one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='contact_email'")) {
    $db->pdo->exec('ALTER TABLE requests ADD COLUMN contact_email VARCHAR(190) NULL AFTER claimed_name');
}
foreach (['Medicina general','Ortopedia y traumatología','Medicina interna','Ginecología','Pediatría','Dermatología','Cardiología','Neurología','Alergología'] as $name) {
    $db->run('INSERT IGNORE INTO specialties (name) VALUES (?)', [$name]);
}
if (!$db->one('SELECT id FROM admins LIMIT 1')) {
    $password = bin2hex(random_bytes(10));
    $db->insert('admins', ['email' => 'admin@citaya.local', 'name' => 'Administrador', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    if (!is_dir($root . '/.runtime')) mkdir($root . '/.runtime', 0700, true);
    file_put_contents($root . '/.runtime/first-login.txt', "Acceso local al repositorio\nUsuario: admin@citaya.local\nContraseña: $password\n");
    echo "Credenciales iniciales guardadas en .runtime/first-login.txt\n";
}
echo "Base de datos y almacenamiento preparados.\n";
