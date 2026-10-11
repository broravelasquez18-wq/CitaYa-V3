<?php
declare(strict_types=1);
// Crea un administrador o restablece su contraseña. Genera una contraseña aleatoria y la muestra una sola vez.
// Uso (por ejemplo en la terminal del contenedor app de Dokploy): php bin/admin-password.php correo@dominio.com "Nombre"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$email = strtolower(trim((string)($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Uso: php bin/admin-password.php correo@dominio.com \"Nombre\"\n"); exit(1); }
$name = trim((string)($argv[2] ?? 'Administrador')) ?: 'Administrador';
$password = substr(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), 0, 16);
$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $app->db->one('SELECT id FROM admins WHERE email=?', [$email]);
if ($existing) $app->db->run('UPDATE admins SET password_hash=?,active=1 WHERE id=?', [$hash, $existing['id']]);
else $app->db->insert('admins', ['email' => $email, 'name' => mb_substr($name, 0, 120), 'password_hash' => $hash, 'active' => 1]);
echo ($existing ? 'Contraseña restablecida' : 'Administrador creado') . " para $email\nContraseña: $password\nGuárdala ahora: no se vuelve a mostrar.\n";
