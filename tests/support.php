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
    say($phone, (string)random_int(7000000000, 7999999999)); // documento nuevo: enlace del formulario
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
    rejects(fn() => $staff->setPassword($employee, 'corta', 'corta'), 'La contraseña manual exige al menos 6 caracteres', '6 caracteres');
    rejects(fn() => $staff->setPassword($employee, 'ClaveManual2026', 'ClaveManual2027'), 'La confirmación debe coincidir', 'confirmación');
    $employeeDocument = (string)$staff->find($employee)['document_number'];
    check(password_verify('ClaveManual2026', $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'Un intento rechazado no cambia la contraseña');
    $staff->setPassword($employee, $employeeDocument, $employeeDocument);
    check(password_verify($employeeDocument, $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'Por ahora se permite usar la cédula como contraseña');
    $staff->setPassword($employee, '43123456', '43123456');
    check(password_verify('43123456', $db->one('SELECT password_hash FROM admins WHERE id=?', [$employee])['password_hash']), 'Sirve una cédula corta de 8 dígitos como contraseña');

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
    $report = $staff->report($employee);
    check($report['member']['id'] == $employee && $report['mix'] === ['approved' => 1, 'not_registered' => 0, 'assigned' => 0] && $report['progress']['month']['requests'] === 1, '«Ver avance» reúne la cuenta, el avance y las respuestas del mes por tipo');
    check($staff->resolutionCounts($admin, date('Y-m-01 00:00:00')) === ['approved' => 0, 'not_registered' => 0, 'assigned' => 0] && $staff->report(999999999) === null, 'Cada persona ve solo sus respuestas y una cuenta inexistente no tiene avance');

    // --- Menú y reporte que resuelve el bot (no le llegó) ---
    say($phone, 'hola');
    check(str_contains(lastTo($phone), '*1.* Solicitar historia clínica') && str_contains(lastTo($phone), '*2.* Reportar un error'), 'El menú del bot ofrece las dos opciones');
    say($phone, '2');
    check(str_contains(lastTo($phone), 'número de documento') && !str_contains(lastTo($phone), 'HC-'), 'Al reportar, el bot pide primero el documento y no muestra ninguna historia');
    say($phone, '7.000.0001');
    check(str_contains(lastTo($phone), '¿Qué problema tiene tu historia?') && str_contains(lastTo($phone), 'No me llegó'), 'Con el documento validado y una sola historia, el bot pregunta directamente el tipo de problema');
    $delivery = $db->one("SELECT id FROM whatsapp_outbox WHERE request_id=? AND kind='response'", [$requestId]);
    say($phone, '1');
    $requeued = $db->one('SELECT status,progress FROM whatsapp_outbox WHERE id=?', [$delivery['id']]);
    $auto = $db->one("SELECT * FROM support_tickets WHERE phone=? ORDER BY id DESC LIMIT 1", [$phone]);
    check($requeued['status'] === 'queued' && (int)$requeued['progress'] === 0 && $auto['status'] === 'auto_resolved' && str_contains(lastTo($phone), 'Te reenviamos'), '«No me llegó»: el bot reenvía la historia solo y no molesta a un empleado');
    while ($bot->tick());
    check($db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$delivery['id']])['status'] === 'simulated', 'El reenvío vuelve a salir completo');

    // --- Reporte que requiere empleado ---
    say($phone, 'quiero reportar un error');
    say($phone, '70000001');
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
    check(!str_contains(lastTo($phone), 'Soy el asistente'), 'Mientras el reporte está abierto el bot no responde con el menú');
    check($db->one('SELECT unread FROM support_tickets WHERE id=?', [$ticket['id']])['unread'] == 0, 'Abrir el chat marca los mensajes como leídos');
    check($desk->ticket((int)$ticket['id'], ['id' => $other, 'role' => 'employee']) === null && $desk->ticket((int)$ticket['id'], $adminView, false) !== null, 'Otro empleado no ve el reporte; el administrador sí');
    $desk->send((int)$ticket['id'], $employeeView, 'Hola, ya corregimos el apellido. Te enviamos la historia de nuevo.');
    check(lastTo($phone) === 'Hola, ya corregimos el apellido. Te enviamos la historia de nuevo.', 'La respuesta del empleado sale por el WhatsApp del bot');
    rejects(fn() => $desk->send((int)$ticket['id'], ['id' => $other, 'role' => 'employee'], 'hola'), 'Un empleado no puede responder un reporte ajeno', 'no está asignado');

    // --- Adjuntos del chat: fotos/PDF del paciente y PDF del personal ---
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $media = fn(string $id, string $type, array $file) => ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $phone, 'type' => $type, $type => $file]]]]]]]];
    $bot->receive($media('img-1', 'image', ['mime_type' => 'image/png', 'caption' => 'Así me sale el apellido', 'data' => base64_encode($png)]));
    $photo = $db->one('SELECT * FROM support_messages WHERE wa_message_id=?', ['img-1']);
    $stored = $desk->attachment((int)$photo['id'], $employeeView);
    check($stored && $stored['mime'] === 'image/png' && file_get_contents($stored['path']) === $png && $stored['name'] === 'imagen.png'
        && end($desk->ticket((int)$ticket['id'], $employeeView)['messages'])['body'] === 'Así me sale el apellido', 'La foto del paciente llega al chat del reporte con su pie de foto');
    check($desk->attachment((int)$photo['id'], ['id' => $other, 'role' => 'employee']) === null && $desk->attachment((int)$photo['id'], $adminView) !== null, 'Otro empleado no puede abrir la foto; el administrador sí');
    $bot->receive($media('doc-1', 'document', ['mime_type' => 'application/pdf', 'filename' => 'mi cédula.pdf', 'data' => base64_encode((string)file_get_contents($pdf))]));
    check($desk->attachment((int)$db->one('SELECT id FROM support_messages WHERE wa_message_id=?', ['doc-1'])['id'], $employeeView)['name'] === 'mi cédula.pdf', 'El PDF que manda el paciente también llega al chat');
    $bot->receive($media('doc-2', 'document', ['mime_type' => 'application/pdf', 'filename' => 'virus.pdf', 'data' => base64_encode('MZ no es un PDF')]));
    $bot->receive($media('img-2', 'image', ['mime_type' => 'image/jpeg']));
    $notes = $db->all("SELECT * FROM support_messages WHERE wa_message_id IN ('doc-2','img-2')");
    check(count($notes) === 2 && !$notes[0]['attachment_storage'] && !$notes[1]['attachment_storage']
        && str_contains(App\Crypto::open($notes[0]['body'], $config['app_key']), 'no se puede mostrar'), 'Un archivo de otro tipo o que no se pudo descargar queda anotado en el chat, sin guardarse');
    check(!str_contains(lastTo($phone), 'Soy el asistente'), 'Las fotos con el reporte abierto no disparan el menú del bot');

    $desk->send((int)$ticket['id'], $employeeView, 'Te enviamos la historia corregida.', ['path' => $pdf, 'name' => 'historia corregida.pdf']);
    $sent = $db->one("SELECT * FROM support_messages WHERE ticket_id=? AND direction='out' ORDER BY id DESC LIMIT 1", [$ticket['id']]);
    $job = $db->one('SELECT * FROM whatsapp_outbox WHERE id=?', [$sent['outbox_id']]);
    while ($bot->tick());
    $preview = json_decode((string)file_get_contents($config['storage_path'] . '/wa-outbox/' . $job['id'] . '.json'), true);
    check($job['kind'] === 'support_file' && $sent['attachment_mime'] === 'application/pdf' && $db->one('SELECT status FROM whatsapp_outbox WHERE id=?', [$job['id']])['status'] === 'simulated'
        && $preview['messages'] === [['type' => 'document', 'filename' => 'historia corregida.pdf', 'caption' => 'Te enviamos la historia corregida.']], 'El personal envía un PDF por el chat y sale por WhatsApp como documento con el texto de pie');
    $desk->send((int)$ticket['id'], $employeeView, '', ['path' => $pdf, 'name' => 'sin-texto']);
    $alone = $db->one("SELECT * FROM support_messages WHERE ticket_id=? AND direction='out' ORDER BY id DESC LIMIT 1", [$ticket['id']]);
    check($alone['attachment_name'] === 'sin-texto.pdf' && App\Crypto::open($alone['body'], $config['app_key']) === '', 'Se puede enviar solo el PDF, sin texto');
    file_put_contents($config['storage_path'] . '/foto.png', $png);
    rejects(fn() => $desk->send((int)$ticket['id'], $employeeView, 'foto', ['path' => $config['storage_path'] . '/foto.png', 'name' => 'foto.pdf']), 'El personal solo puede enviar PDF (se revisa el contenido, no el nombre)', 'PDF');
    rejects(fn() => $desk->send((int)$ticket['id'], $employeeView, '  '), 'Sin texto ni PDF no se envía nada', 'adjunta un PDF');
    $desk->close((int)$ticket['id'], $employeeView);
    check($db->one('SELECT status FROM support_tickets WHERE id=?', [$ticket['id']])['status'] === 'closed' && str_contains(lastTo($phone), 'quedó resuelto') && $staff->progress($employee)['day']['reports'] === 1, 'Cerrar el reporte avisa al paciente y cuenta en el avance del empleado');
    say($phone, 'hola');
    check(str_contains(lastTo($phone), 'Soy el asistente'), 'Con el reporte cerrado el paciente vuelve al menú');

    // --- Otros casos del flujo ---
    say($phone, '2');
    say($phone, '0');
    check(!$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', [$phone]) && str_contains(lastTo($phone), 'Soy el asistente'), 'Responder 0 cancela el reporte y vuelve al menú');
    say('573009998888', '2');
    say('573009998888', '70000001');
    check(str_contains(lastTo('573009998888'), 'no tiene historias enviadas a este WhatsApp'), 'Un documento ajeno desde otro WhatsApp no muestra historias');
    say('573009998888', 'abc');
    check(str_contains(lastTo('573009998888'), 'Escribe solo el número'), 'Un documento mal escrito se vuelve a pedir sin gastar intentos');
    say('573009998888', '70000009'); say('573009998888', '70000008');
    check(str_contains(lastTo('573009998888'), 'No pudimos validar') && !$db->one('SELECT phone FROM whatsapp_sessions WHERE phone=?', ['573009998888']), 'Tras tres documentos que no coinciden, el reporte se cierra');
    $foreign = pendingRequest($phone, '70000002');
    $app->respondCase($foreign, $admin, 'approved', 'Historia de otro paciente.', [['path' => $pdf, 'name' => 'historia.pdf']]);
    $second = pendingRequest($phone, '70000001');
    $app->respondCase($second, $admin, 'approved', 'Segunda historia.', [['path' => $pdf, 'name' => 'historia.pdf']]);
    say($phone, '2');
    say($phone, '70000001');
    check(!str_contains(lastTo($phone), $app->request($foreign)['reference']), 'Solo se listan las historias del documento validado, no las de otro paciente del mismo WhatsApp');
    check(str_contains(lastTo($phone), '¿Sobre cuál historia es el reporte?') && str_contains(lastTo($phone), '*1.* Solicitada el '), 'Con varias historias, el bot pregunta sobre cuál es el reporte');
    check(!str_contains(lastTo($phone), $app->request($second)['reference']) && preg_match('/\d{1,2} de [a-z]+ de \d{4}, \d{1,2}:\d{2} [ap]\. m\./u', lastTo($phone)), 'La lista no muestra el radicado y da la fecha con mes y hora');
    say($phone, '9');
    check(str_contains(lastTo($phone), 'Responde con el número'), 'Una opción inválida se vuelve a pedir');
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

    // --- Plantillas creadas por el administrador ---
    $templates = new App\MessageTemplates($app);
    $approvedId = $templates->saveCustom(null, '  Aprobado   con anexos ', 'approved', "Hola, {nombre}. Tu solicitud *{radicado}* fue aprobada.", $admin);
    $created = $db->one('SELECT * FROM custom_templates WHERE id=?', [$approvedId]);
    check($created['name'] === 'Aprobado con anexos' && $created['usage_code'] === 'approved', 'Crea una plantilla de respuesta «Aprobado» con el nombre limpio');
    check(App\MessageTemplates::renderCustom($created, ['nombre' => 'Ana', 'radicado' => 'HC-1'], true) === 'Hola, Ana. Tu solicitud HC-1 fue aprobada.', 'La plantilla creada reemplaza sus variables (sin asteriscos para el correo)');
    rejects(fn() => $templates->saveCustom(null, 'Aprobado con anexos', 'approved', 'Otro texto suficiente.', $admin), 'No permite dos plantillas con el mismo nombre y uso', 'Ya existe');
    rejects(fn() => $templates->saveCustom(null, 'Con variable ajena', 'approved', 'Hola {enlace} y {reporte}.', $admin), 'Solo admite las variables de su uso', '{enlace}, {reporte}');
    rejects(fn() => $templates->saveCustom(null, 'Sin enlace', 'not_registered', 'No encontramos tu historia, {nombre}.', $admin), 'Las respuestas «No Registra» exigen {enlace}', '{enlace}');
    rejects(fn() => $templates->saveCustom(null, 'Uso inventado', 'email', 'Texto suficientemente largo.', $admin), 'Rechaza un uso que no existe', 'para qué');
    rejects(fn() => $templates->saveCustom(null, 'Ok', 'approved', 'Texto suficientemente largo.', $admin), 'Exige un nombre de al menos 3 caracteres', 'nombre');
    $chatId = $templates->saveCustom(null, 'Seguimos revisando', 'report_chat', 'Hola, {nombre}. Seguimos revisando tu reporte {reporte}.', $admin);
    $templates->saveCustom($approvedId, 'Aprobado completo', 'approved', 'Tu solicitud {radicado} fue aprobada.', $admin);
    check($db->one('SELECT name FROM custom_templates WHERE id=?', [$approvedId])['name'] === 'Aprobado completo' && count($templates->custom()) === 2
        && array_column($templates->custom('report_chat'), 'id') === [$chatId], 'Edita una plantilla y las lista por uso');
    $templates->deleteCustom($chatId, $admin);
    check(array_column($templates->custom(), 'id') === [$approvedId], 'Elimina una plantilla');
    rejects(fn() => $templates->deleteCustom($chatId, $admin), 'No elimina dos veces la misma plantilla', 'ya no existe');

    // --- Interfaz para n8n ---
    $api = new App\N8nApi($app);
    $result = $api->handle(['action' => 'conversation.handle', 'phone' => $phone, 'message_id' => 'n8n-1', 'text' => 'Sigo esperando']);
    check($result['handled'] === true && count($desk->ticket((int)$byAdmin['id'], $adminView, false)['messages']) === 2, 'conversation.handle: n8n entrega el mensaje al chat abierto');
    $result = $api->handle(['action' => 'conversation.handle', 'phone' => $phone, 'message_id' => 'n8n-3', 'text' => '', 'media' => ['data' => base64_encode($png), 'filename' => 'captura.png', 'mime_type' => 'image/png']]);
    $last = end($desk->ticket((int)$byAdmin['id'], $adminView, false)['messages']);
    check($result['handled'] === true && $last['attachment_mime'] === 'image/png' && $last['attachment_name'] === 'captura.png', 'conversation.handle: la foto que reenvía n8n llega al chat abierto');
    check($api->handle(['action' => 'conversation.handle', 'phone' => '573007770000', 'message_id' => 'n8n-2', 'text' => 'hola'])['handled'] === false, 'conversation.handle: sin reporte ni opción 2, n8n sigue con el bot normal');

    echo "\nPASS: $checks comprobaciones. Sin envíos externos.\n";
} finally {
    if (!preg_match('/^citaya_test_[a-f0-9]{10}$/', $databaseName)) throw new RuntimeException('Invalid test database name');
    $server->exec("DROP DATABASE `$databaseName`");
    foreach (glob($config['storage_path'] . '/*.png') ?: [] as $file) unlink($file);
    foreach (['histories', 'outbox', 'wa-outbox', 'responses', 'support'] as $dir) { if (!is_dir($config['storage_path'] . '/' . $dir)) continue; foreach (glob($config['storage_path'] . '/' . $dir . '/*') as $file) unlink($file); rmdir($config['storage_path'] . '/' . $dir); }
    rmdir($config['storage_path']);
}
