<?php require_once __DIR__ . '/icons.php';
$isAdmin = ($_SESSION['admin_role'] ?? 'admin') === 'admin';
$fromHistory = ($_GET['from'] ?? '') === 'history';
// Secciones del rail: [página, título, ícono, activo, solo administradores, contador].
$sections = [
    ['deliveries', 'Solicitudes', 'inbox', $page === 'deliveries' || ($page === 'delivery' && !$fromHistory) || $page === 'case-history', false, (int)($pendingCount ?? 0), 'pendientes'],
    ['reports', 'Reportes de error', 'flag', in_array($page, ['reports', 'report'], true), false, (int)($supportCount ?? 0), 'por atender'],
    ['progress', 'Mi avance', 'gauge', $page === 'progress', false, 0, ''],
    ['admin', 'Repositorio', 'archive', $page === 'admin', true, 0, ''],
    ['history', 'Historial de enviadas', 'check-circle', $page === 'history' || ($page === 'delivery' && $fromHistory), true, 0, ''],
    ['analytics', 'Análisis mensual', 'chart', $page === 'analytics', true, 0, ''],
    ['employees', 'Empleados', 'users', $page === 'employees', true, 0, ''],
    ['templates', 'Plantillas', 'template', $page === 'templates', true, 0, ''],
    ['inbox', 'Bandeja local', 'mail', $page === 'inbox', true, 0, ''],
];
if ($isAdmin && (!empty($whatsappDisconnected) || $page === 'whatsapp')) $sections[] = ['whatsapp', 'WhatsApp del bot', 'chat', $page === 'whatsapp', true, 0, ''];
$titles = [
    'admin' => ['Repositorio', 'Historias cargadas desde PDF y su disponibilidad para entrega.'],
    'deliveries' => ['Solicitudes', 'Busca al paciente, revisa el seguimiento y responde lo pendiente.'],
    'delivery' => ['Detalle de la solicitud', 'Datos del paciente, contacto y gestión del caso.'],
    'case-history' => ['Historial del caso', 'Radicación, respuestas y envíos en orden.'],
    'history' => ['Historial de enviadas', 'Solicitudes aprobadas automáticamente y con envío aceptado por el servidor de correo.'],
    'reports' => ['Reportes de error', 'Errores que los pacientes reportaron por WhatsApp y el bot no resolvió.'],
    'report' => ['Reporte de error', 'Conversación con el paciente por el número del bot.'],
    'progress' => ['Mi avance', 'Lo que has respondido hoy, este mes y este año.'],
    'employees' => ['Empleados', 'Cuentas del personal, roles y avance.'],
    'inbox' => ['Bandeja local', 'Correos de simulación guardados en el servidor.'],
    'analytics' => ['Análisis mensual', 'Actividad del sistema y atenciones por especialidad.'],
    'templates' => ['Plantillas de mensajes', 'Textos que recibe el paciente por WhatsApp y correo.'],
    'whatsapp' => ['WhatsApp del bot', 'Vinculación del número que atiende a los pacientes.'],
];
[$pageTitle, $pageLead] = $titles[$page] ?? ['Acceso interno', ''];
// Acción principal de cada página: abre el drawer de su formulario (sin JavaScript, el ancla lo muestra).
$pageActions = [
    'admin' => '<a class="button primary" href="#upload-drawer" data-open="upload-drawer">' . ui_icon('upload', 18) . 'Cargar historias</a>',
    'employees' => '<a class="button primary" href="#staff-create-drawer" data-open="staff-create-drawer">' . ui_icon('users', 18) . 'Agregar empleado</a>',
][$page] ?? '';
?>
<div class="console">
<aside class="rail" aria-label="Secciones del acceso interno">
<nav class="rail-nav tabs" aria-label="Secciones">
<?php foreach ($sections as [$target, $label, $iconName, $active, $adminOnly, $count, $countLabel]): if ($adminOnly && !$isAdmin) continue; ?>
<a class="<?= $active ? 'selected' : '' ?>" href="?page=<?= $target ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= ui_icon($iconName, 19) ?><span class="rail-label"><?= h($label) ?></span><?php if ($count): ?> <span class="tab-count" aria-label="<?= $count ?> <?= $countLabel ?>"><?= $count ?></span><?php endif ?><?php if ($target === 'whatsapp' && !empty($whatsappDisconnected)): ?><span class="rail-alert" aria-label="desconectado"></span><?php endif ?></a>
<?php endforeach ?>
</nav>
<div class="rail-session"><span class="rail-user"><?= ui_icon('user', 18) ?><span><strong><?= h($_SESSION['admin_name'] ?? '') ?></strong><small><?= $isAdmin ? 'Administrador' : 'Empleado' ?></small></span></span><form method="post"><?= csrf() ?><input type="hidden" name="action" value="logout"><button class="rail-logout" aria-label="Cerrar sesión" title="Cerrar sesión"><?= ui_icon('logout', 18) ?><span>Salir</span></button></form></div>
</aside>
<div class="console-main">
<header class="page-head"><div><h1><?= h($pageTitle) ?></h1><?php if ($pageLead !== ''): ?><p><?= h($pageLead) ?></p><?php endif ?></div><?php if ($pageActions !== ''): ?><div class="page-actions"><?= $pageActions ?></div><?php endif ?></header>
<?php if (!empty($whatsappDisconnected) && $page !== 'whatsapp'): ?><dialog id="wa-down-modal" class="modal alert-modal" aria-labelledby="wa-down-title" aria-describedby="wa-down-text" data-auto-open data-once="wa-down"><div class="alert-modal-icon stop"><?= ui_icon('chat', 26) ?></div><h2 id="wa-down-title">El WhatsApp del bot está desconectado</h2><p id="wa-down-text">Los pacientes que escriban al bot no reciben respuesta hasta que lo vuelvas a vincular.</p><div class="alert-modal-actions"><button type="button" class="button secondary" data-close>Más tarde</button><?php if ($isAdmin): ?><a class="button primary" href="?page=whatsapp">Vincular ahora</a><?php endif ?></div></dialog><?php endif ?>
<?php if ($page === 'employees'): require __DIR__ . '/employees.php'; ?>
<?php elseif ($page === 'progress'): require __DIR__ . '/progress.php'; ?>
<?php elseif ($page === 'reports'): require __DIR__ . '/reports.php'; ?>
<?php elseif ($page === 'report'): require __DIR__ . '/report.php'; ?>
<?php elseif ($page === 'analytics'): require __DIR__ . '/analytics.php'; ?>
<?php elseif ($page === 'templates'): require __DIR__ . '/templates.php'; ?>
<?php elseif ($page === 'whatsapp'): require __DIR__ . '/whatsapp-link.php'; ?>
<?php elseif ($page === 'delivery'): require __DIR__ . '/delivery.php'; ?>
<?php elseif ($page === 'case-history'): require __DIR__ . '/case-history.php'; ?>
<?php elseif (in_array($page, ['deliveries','history'], true)): require __DIR__ . '/deliveries.php'; ?>
<?php elseif ($page === 'inbox'): ?>
<section class="card"><h2>Correos de simulación</h2><p class="muted">Estos mensajes se generaron con PHPMailer y se guardaron localmente. No se enviaron al destinatario externo.</p>
<?php if ($app->config['mail_transport'] !== 'local'): ?><div class="notice"><?= ui_icon('info') ?><p>La bandeja local está desactivada porque el transporte configurado es SMTP.</p></div>
<?php else: $messages = $app->db->all("SELECT j.*,r.reference FROM jobs j JOIN requests r ON r.id=j.request_id WHERE j.status='simulated' ORDER BY j.id DESC LIMIT 30"); ?>
<?php if (!$messages): ?><div class="empty-state"><?= ui_icon('mail', 28) ?><p>Aún no hay correos. Crea una solicitud y mantén el procesador de cola en ejecución.</p></div><?php endif ?>
<div class="mail-list"><?php foreach ($messages as $message): $mailPath = $app->config['storage_path'] . '/outbox/' . $message['id'] . '.eml'; $code = null;
if ($message['kind'] === 'otp' && is_file($mailPath)) { preg_match('/Tu código de verificación es: ([0-9]{6})/u', quoted_printable_decode(file_get_contents($mailPath)), $codeMatch); $code = $codeMatch[1] ?? null; }
?><article class="mail-item"><div class="mail-top"><span class="pill"><?= $message['kind'] === 'otp' ? 'Verificación' : 'Historia completa' ?></span><small class="mono"><?= h($message['sent_at']) ?></small></div><h3 class="mono"><?= h($message['reference']) ?></h3><p class="muted">Para: <?= h($message['recipient']) ?></p><?php if ($code): ?><p>Código de prueba: <strong class="mail-code"><?= h($code) ?></strong></p><small>Puede haber vencido o haber sido reemplazado por un código más reciente.</small><?php else: ?><p>Mensaje preparado con los documentos del paquete solicitado.</p><?php endif ?><a class="back-link" href="?page=mail&amp;id=<?= $message['id'] ?>"><?= ui_icon('file', 16) ?>Descargar correo .eml</a></article><?php endforeach ?></div>
<?php endif ?></section>
<?php else:
$counts = [
    'Pacientes' => $app->db->one('SELECT COUNT(*) n FROM patients')['n'],
    'Atenciones' => $app->db->one('SELECT COUNT(*) n FROM encounters')['n'],
    'Paquetes disponibles' => $app->db->one("SELECT COUNT(*) n FROM packages WHERE status='available'")['n'],
    'Solicitudes' => $app->db->one('SELECT COUNT(*) n FROM requests')['n'],
]; ?>
<dl class="stats"><?php foreach ($counts as $label => $value): ?><div class="stat"><dt><?= h($label) ?></dt><dd><?= h(number_format((int)$value, 0, ',', '.')) ?></dd></div><?php endforeach ?></dl>
<?php require __DIR__ . '/upload-center.php'; ?>
<section class="card spaced"><h2>Historias registradas</h2><p class="muted">Las 50 más recientes. Retirar un paquete impide nuevas entregas.</p><div class="table-wrap"><table class="responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Paciente</th><th scope="col" role="columnheader">Atención</th><th scope="col" role="columnheader">Paquete</th><th scope="col" role="columnheader">Estado</th><th scope="col" role="columnheader">Acción</th></tr></thead><tbody role="rowgroup">
<?php $packages = $app->db->all('SELECT p.*,e.attended_at,e.admission,s.name specialty,pa.full_name,pa.document_type,pa.document_number,(SELECT COUNT(*) FROM clinical_files f WHERE f.package_id=p.id) files,(SELECT SUM(f.page_count) FROM clinical_files f WHERE f.package_id=p.id) pages FROM packages p JOIN encounters e ON e.id=p.encounter_id JOIN patients pa ON pa.id=e.patient_id JOIN specialties s ON s.id=e.specialty_id ORDER BY p.id DESC LIMIT 50'); ?>
<?php if (!$packages): ?><tr role="row"><td role="cell" colspan="5" class="empty-cell">Todavía no hay historias cargadas. Usa «Cargar historias clínicas» para empezar.</td></tr><?php endif ?>
<?php foreach ($packages as $package): ?><tr role="row"><td role="cell" data-label="Paciente"><strong><?= h($package['full_name']) ?></strong><small class="mono"><?= h($package['document_type'] . ' ' . $package['document_number']) ?></small></td><td role="cell" data-label="Atención"><?= h($package['specialty']) ?><small><span class="mono"><?= date('d/m/Y', strtotime($package['attended_at'])) ?></span> · Ingreso <span class="mono"><?= h($package['admission']) ?></span></small></td><td role="cell" data-label="Paquete"><?= $package['files'] ?> PDF<?= $package['pages'] ? ' · ' . (int)$package['pages'] . ((int)$package['pages'] === 1 ? ' página' : ' páginas') : '' ?> · v<?= $package['version'] ?></td><td role="cell" data-label="Estado"><?= $package['status'] === 'available' ? '<span class="pill ok">' . ui_icon('check', 14) . 'Disponible</span>' : '<span class="pill stop">' . ui_icon('x', 14) . 'Retirado / incompleto</span>' ?></td><td role="cell" data-label="Acción"><?php if ($package['status'] === 'available'): ?><form method="post" data-confirm="Retirar este paquete impedirá nuevas entregas. Los correos ya enviados no se pueden recuperar." data-confirm-title="¿Retirar el paquete de <?= h($package['full_name']) ?>?" data-confirm-label="Retirar paquete" data-confirm-tone="danger"><?= csrf() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="package_id" value="<?= $package['id'] ?>"><button class="text-button danger">Retirar</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<?php endif ?>
</div>
</div>
