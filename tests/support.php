<?php
declare(strict_types=1);
// Roles, empleados, reporte de errores por WhatsApp y chat (SCRUM-46/47/48). Base temporal, sin envíos externos.
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
$config['whatsapp_transport'] = 'local';
$config['whatsapp_dispatch'] = 'worker';
$config['public_url'] = 'https://portal.example.test';
foreach (['histories', 'outbox', 'wa-outbox', 'responses'] as $dir) mkdir($config['storage_path'] . '/' . $dir, 0700, true);
$db = new App\Database($config);
$app = new App\Application($db, $config);
$bot = $app->whatsapp();
$desk = new App\SupportDesk($app);
$staff = new App\Staff($app);
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "OK $label\n"; }
function rejects(callable $fn, string $label, string $contains = ''): void {
    try { $fn(); } catch (DomainException $e) { check($contains === '' || str_contains($e->getMessage(), $contains), $label); return; }
    throw new RuntimeException('FAIL (no rechazó): ' . $label);
}
/** Último texto encolado para un número. */
function lastTo(string $phone): string { global $db, $config; $row = $db->one("SELECT encrypted_body FROM whatsapp_outbox WHERE phone=? AND kind='text' ORDER BY id DESC LIMIT 1", [$phone]); return $row ? App\Crypto::open($row['encrypted_body'], $config['app_key']) : ''; }
function inbound(string $id, string $phone, string $text): array { return ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $phone, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]; }
function say(string $phone, string $text): void { global $bot; static $n = 0; $bot->receive(inbound('msg-' . (++$n) . '-' . bin2hex(random_bytes(3)), $phone, $text)); }
/** Solicitud por WhatsApp para un documento sin historia (queda pendiente). */
function pendingRequest(string $phone, string $document): int {
    global $app, $bot, $db, $config;
    say($phone, 'historia');
    $row = $db->one("SELECT encrypted_body FROM whatsapp_outbox WHERE phone=? AND kind='text' ORDER BY id DESC LIMIT 1", [$phone]);
    preg_match('/wa=([a-f0-9]{48})/', App\Crypto::open($row['encrypted_body'], $config['app_key']), $m);
    return $app->createRequest(['document_type' => 'CC', 'document_number' => $document, 'full_name' => 'Paciente Ficticio', 'approximate_date' => date('Y-m-d'), 'specialty_id' => 0, 'email' => 'p' . $document . '@example.test'], 'ip-' . $document, true, $bot->findLink($m[1]));
}
try {
    $db->pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $db->insert('specialties', ['name' => 'Medicina general']);
    $admin = $db->insert('admins', ['email' => 'admin@example.test', 'name' => 'Admin Prueba', 'role' => 'admin', 'password_hash' => 'x', 'active' => 1]);

    // --- Empleados (SCRUM-46) ---
    $created = $staff->create('1.088.000.111', 'Laura  Empleada', 'Laura@Example.test', 'employee');
    $employee = $created['id'];
    $laura = $staff->find($employee);
    check(strlen($created['password']) === 16 && password_verify($created['password'], $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash'])
        && $laura['role'] === 'employee' && $laura['email'] === 'laura@example.test' && $laura['document_number'] === '1088000111' && $laura['name'] === 'Laura Empleada', 'Crea un empleado con cédula (sin puntos), contraseña aleatoria y correo en minúsculas');
    check((int)$staff->findByDocument('1088000111')['id'] === $employee && count($staff->all('1088000')) === 1 && count($staff->all('laura')) === 1, 'Se encuentra por cédula y se busca por cédula, nombre o correo');
    rejects(fn() => $staff->create('1088000111', 'Otra Persona', 'otra@example.test', 'employee'), 'No permite dos cuentas con la misma cédula', 'cédula');
    rejects(fn() => $staff->create('2000', 'Otra Persona', 'otra@example.test', 'employee'), 'Rechaza una cédula inválida', 'cédula');
    rejects(fn() => $staff->create('2000000001', 'Otra Persona', 'laura@example.test', 'employee'), 'No permite dos cuentas con el mismo correo', 'Ya existe');
    rejects(fn() => $staff->create('2000000001', 'Otra Persona', 'otra@example.test', 'jefe'), 'Rechaza un rol inventado', 'rol');
    rejects(fn() => $staff->setActive($admin, $admin, false), 'Nadie puede desactivar su propia cuenta', 'propia');
    rejects(fn() => $staff->update($admin, $employee, '9000000001', 'Admin Prueba', 'admin@example.test', 'employee'), 'Debe quedar al menos un administrador activo', 'al menos un administrador');
    $other = $staff->create('2000000002', 'Pedro Empleado', 'pedro@example.test', 'employee')['id'];
    rejects(fn() => $staff->update($other, $admin, '1088000111', 'Pedro Empleado', 'pedro@example.test', 'employee'), 'Al editar no se puede usar la cédula de otra cuenta', 'cédula');
    $staff->update($other, $admin, '2000000003', 'Pedro Pérez', 'pedro.perez@example.test', 'employee');
    check($staff->find($other)['document_number'] === '2000000003' && $staff->find($other)['email'] === 'pedro.perez@example.test' && $staff->find($other)['name'] === 'Pedro Pérez', 'Editar cambia cédula, nombre y correo');
    $staff->setActive($other, $admin, false);
    check(!$staff->find($other)['active'], 'El administrador puede desactivar una cuenta');
    $temporary = $staff->create('2000000004', 'Cuenta Temporal', 'temporal@example.test', 'employee')['id'];
    check($staff->delete($temporary, $admin) === true && !$db->one('SELECT id FROM admins WHERE id=?', [$temporary]), 'Eliminar una cuenta sin actividad la borra del todo');
    rejects(fn() => $staff->delete($admin, $admin), 'Nadie puede eliminar su propia cuenta', 'propia');
    $newPassword = $staff->resetPassword($employee);
    check($newPassword !== $created['password'] && password_verify($newPassword, $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'Restablecer genera una contraseña nueva');
    $staff->setPassword($employee, 'ClaveManual2026', 'ClaveManual2026');
    check(password_verify('ClaveManual2026', $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'El administrador puede escribir la contraseña a mano');
    rejects(fn() => $staff->setPassword($employee, 'corta', 'corta'), 'La contraseña manual exige al menos 10 caracteres', '10 caracteres');
    rejects(fn() => $staff->setPassword($employee, 'ClaveManual2026', 'ClaveManual2027'), 'La confirmación debe coincidir', 'confirmación');
    $employeeDocument = (string)$staff->find($employee)['document_number'];
    rejects(fn() => $staff->setPassword($employee, $employeeDocument, $employeeDocument), 'La contraseña no puede ser la cédula', 'cédula');
    check(password_verify('ClaveManual2026', $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'Un intento rechazado no cambia la contraseña');

    // --- Avance: el empleado responde una solicitud ---
    $phone = '573004440000';
    $requestId = pendingRequest($phone, '70000001');
    $pdf = $config['storage_path'] . '/histories/respuesta.pdf';
    file_put_contents($pdf, "%PDF-1.4\nHistoria ficticia\n%%EOF");
    $app->respondCase($requestId, $employee, 'approved', 'Te enviamos tu historia clínica.', [['path' => $pdf, 'name' => 'historia.pdf']]);
    while ($bot->tick());
    $progress = $staff->progress($employee);
    check($progress['day']['requests'] === 1 && $progress['month']['requests'] === 1 && $progress['year']['requests'] === 1 && $staff->progress($admin)['day']['requests'] === 0, 'El avance cuenta las solicitudes respondidas por cada persona (día, mes y año)');
    check($staff->recentResponses($employee)[0]['resolution'] === 'approved', '«Mi avance» lista las últimas respuestas');

    // --- Menú y reporte que resuelve el bot (no le llegó) ---
    say($phone, 'hola');
    check(str_contains(lastTo($phone), '*1.* Solicitar historia clínica por atención') && str_contains(lastTo($phone), '*2.* Reportar un error'), 'El menú del bot ofrece las dos opciones');
    say($phone, '2');
    check(str_contains(lastTo($phone), '¿Qué problema tiene tu historia clínica?') && str_contains(lastTo($phone), 'No me llegó'), 'Con una sola historia enviada, el bot pregunta directamente el tipo de problema');
    $delivery = $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='response'", [$requestId]);
    say($phone, '1');
    $requeued = $db->one('SELECT status,progress FROM whatsapp_outbox WHERE id=?', [$delivery['id']]);
    $auto = $db->one("SELECT * FROM support_tickets WHERE phone=? ORDER BY id DESC LIMIT 1", [$phone]);
    check($requeued['status'] === 'queued' && (int)$requeued['progress'] === 0 && $auto['status'] === 'auto_resolved' && str_contains(lastTo($phone), 'reenviando'), '«No me llegó»: el bot reenvía la historia solo y no molesta a un empleado');
    while ($bot->tick());
    check($db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$delivery['id']])['status'] === 'simulated', 'El reenvío vuelve a salir completo');

    // --- Reporte que requiere empleado ---
    say($phone, 'quiero reportar un error');
    say($phone, '2');
    check(str_contains(lastTo($phone), 'Cuéntanos en un mensaje'), 'Para datos incorrectos el bot pide la descripción');
    say($phone, 'Mi segundo apellido está mal escrito');
    $ticket = $db->one("SELECT * FROM support_tickets WHERE phone=? AND status='open'", [$phone]);
    check($ticket && (int)$ticket['assigned_to'] === $employee && $ticket['category'] === 'wrong_data' && str_contains(lastTo($phone), $ticket['reference']), 'Se crea el reporte y se asigna al empleado que respondió esa historia');
    $employeeView = ['id' => $employee, 'role' => 'employee'];
    $adminView = ['id' => $admin, 'role' => 'admin'];
    check($desk->unreadCount($employeeView) === 1 && count($desk->tickets($employeeView)) === 1, 'El empleado ve el reporte pendiente en su panel');
    $notices = $desk->notifications($employeeView);
    check(count($notices) === 1 && $notices[0]['reference'] === $ticket['reference'] && $desk->notifications(['id' => $other, 'role' => 'employee']) === [] && lastTo('573001112222') === '', 'El aviso llega al panel del empleado asignado (no a su WhatsApp ni a otros empleados)');

    // --- Chat ---
    say($phone, '¿Me pueden ayudar pronto?');
    $detail = $desk->ticket((int)$ticket['id'], $employeeView);
    check(array_column($detail['messages'], 'body') === ['Mi segundo apellido está mal escrito', '¿Me pueden ayudar pronto?'] && $detail['description'] === 'Mi segundo apellido está mal escrito', 'Lo que escribe el paciente llega al chat del reporte, no al menú del bot');
    check(!str_contains(lastTo($phone), 'Responde con el número de la opción'), 'Mientras el reporte está abierto el bot no responde con el menú');
    check($db->one('SELECT unread FROM support_tickets WHERE id=?', [$ticket['id']])['unread'] == 0, 'Abrir el chat marca los mensajes como leídos');
    check($desk->ticket((int)$ticket['id'], ['id' => $other, 'role' => 'employee']) === null && $desk->ticket((int)$ticket['id'], $adminView, false) !== null, 'Otro empleado no ve el reporte; el administrador sí');
    $desk->send((int)$ticket['id'], $employeeView, 'Hola, ya corregimos el apellido. Te enviamos la historia de nuevo.');
    check(lastTo($phone) === 'Hola, ya corregimos el apellido. Te enviamos la historia de nuevo.', 'La respuesta del empleado sale por el WhatsApp del bot');
    rejects(fn() => $desk->send((int)$ticket['id'], ['id' => $other, 'role' => 'employee'], 'hola'), 'Un empleado no puede responder un reporte ajeno', 'no está asignado');
    $desk->close((int)$ticket['id'], $employeeView);
    check($db->one('SELECT status FROM support_tickets WHERE id=?', [$ticket['id']])['status'] === 'closed' && str_contains(lastTo($phone), 'quedó resuelto') && $staff->progress($employee)['day']['reports'] === 1, 'Cerrar el reporte avisa al paciente y cuenta en el avance del empleado');
    say($phone, 'hola');
    check(str_contains(lastTo($phone), 'Responde con el número de la opción'), 'Con el reporte cerrado el paciente vuelve al menú');

    // --- Otros casos del flujo ---
    say($phone, '2');
    say($phone, '0');
    check(!$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', [$phone]) && str_contains(lastTo($phone), 'Responde con el número de la opción'), 'Responder 0 cancela el reporte y vuelve al menú');
    say('573009998888', '2');
    check(str_contains(lastTo('573009998888'), 'No encontramos historias clínicas enviadas'), 'Sin historias enviadas a ese WhatsApp, el bot lo indica');
    $second = pendingRequest($phone, '70000002');
    $app->respondCase($second, $admin, 'approved', 'Segunda historia.', [['path' => $pdf, 'name' => 'historia.pdf']]);
    say($phone, '2');
    check(str_contains(lastTo($phone), '¿Sobre cuál historia es el reporte?') && str_contains(lastTo($phone), $app->request($second)['reference']), 'Con varias historias, el bot pregunta sobre cuál es el reporte');
    say($phone, '9');
    check(str_contains(lastTo($phone), 'Responde solo con el número'), 'Una opción inválida se vuelve a pedir');
    say($phone, '1'); say($phone, '4'); say($phone, 'Esa no es mi cita');
    $byAdmin = $db->one("SELECT * FROM support_tickets WHERE phone=? AND status='open'", [$phone]);
    check($byAdmin && (int)$byAdmin['assigned_to'] === $admin && (int)$byAdmin['request_id'] === $second, 'El reporte va a quien respondió esa historia (aquí, el administrador)');

    // --- Eliminar una cuenta con actividad: se conserva el historial y sus reportes pasan a otra persona ---
    $helper = $staff->create('2000000005', 'Empleado de Relevo', 'relevo@example.test', 'employee')['id'];
    $openOfAdmin = (int)$byAdmin['id'];
    $db->run('UPDATE support_tickets SET assigned_to=? WHERE id=?', [$employee, $openOfAdmin]);
    check($staff->delete($employee, $admin) === false, 'Eliminar una cuenta con actividad la archiva');
    $archived = $db->one('SELECT * FROM admins WHERE id=?', [$employee]);
    check($archived['deleted_at'] && !$archived['active'] && $archived['document_number'] === null && $staff->find($employee) === null && $staff->findByDocument('1088000111') === null
        && count(array_filter($staff->all(), fn($m) => (int)$m['id'] === $employee)) === 0, 'La cuenta eliminada no aparece, no puede entrar y libera su cédula y correo');
    check((int)$db->one('SELECT resolved_by FROM requests WHERE id=?', [$requestId])['resolved_by'] === $employee && (int)$db->one('SELECT assigned_to FROM support_tickets WHERE id=?', [$openOfAdmin])['assigned_to'] === $helper, 'Se conserva quién respondió y sus reportes abiertos pasan a otro empleado');
    check($staff->create('1088000111', 'Laura Empleada', 'laura@example.test', 'employee')['id'] !== $employee, 'La cédula y el correo de una cuenta eliminada se pueden volver a usar');

    // --- Interfaz para n8n ---
    $api = new App\N8nApi($app);
    $result = $api->handle(['action' => 'conversation.handle', 'phone' => $phone, 'message_id' => 'n8n-1', 'text' => 'Sigo esperando']);
    check($result['handled'] === true && count($desk->ticket((int)$byAdmin['id'], $adminView, false)['messages']) === 2, 'conversation.handle: n8n entrega el mensaje al chat abierto');
    check($api->handle(['action' => 'conversation.handle', 'phone' => '573007770000', 'message_id' => 'n8n-2', 'text' => 'hola'])['handled'] === false, 'conversation.handle: sin reporte ni opción 2, n8n sigue con el bot normal');

    echo "\nPASS: $checks comprobaciones. Sin envíos externos.\n";
} finally {
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (['histories', 'outbox', 'wa-outbox', 'responses'] as $dir) { if (!is_dir($config['storage_path'] . '/' . $dir)) continue; foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
