<?php require_once __DIR__ . '/icons.php';
$staffPages = ['admin','deliveries','delivery','case-history','history','analytics','templates','whatsapp','employees','progress','reports','report'];
$isStaffPage = in_array($page, $staffPages, true);
$isPortal = isset($steps[$page]);
$bodyClass = $isPortal ? 'patient-portal' : ($isStaffPage ? 'staff' : ($page === 'admin-login' ? 'login' : ''));
// Páginas con fondo animado y cambio de tema claro/oscuro.
$ambient = in_array($page, ['admin-login', 'home'], true);
if ($ambient) $bodyClass .= ' ambient' . ($page === 'home' ? ' request-page' : '');
// La consola interna (con sesión) usa el mismo fondo animado, más tenue, y el mismo cambio de tema.
$consoleAmbient = $isStaffPage && !empty($_SESSION['admin_id']);
if ($consoleAmbient) $bodyClass .= ' console-ambient';
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title><?= $isStaffPage ? 'Acceso interno · CitaYa' : 'Historias clínicas · CitaYa' ?></title>
    <script src="assets/theme.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/theme.js') ?>"></script>
    <link rel="preload" href="assets/fonts/atkinson-hyperlegible-next-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/app.css') ?>"><link rel="stylesheet" href="assets/responsive.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/responsive.css') ?>"><script src="assets/app.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/app.js') ?>" defer></script>
</head>
<body class="<?= $bodyClass ?>">
<?php if ($ambient || $consoleAmbient) { $ambientVariant = $consoleAmbient ? 'console' : 'full'; require __DIR__ . '/ambient.php'; } ?>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<header class="header"><?php if ($isStaffPage && !empty($_SESSION['admin_id'])): ?><button type="button" class="nav-toggle" data-rail-toggle aria-controls="console-rail" aria-expanded="false" aria-label="Abrir el menú de secciones"><?= ui_icon('menu', 22) ?></button><?php endif ?><a class="brand" href="?page=<?= $isStaffPage ? 'deliveries' : 'home' ?>" aria-label="CitaYa, inicio"><span class="brand-mark" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 30 30"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h19A2.5 2.5 0 0 1 27 7.5V11a4 4 0 0 0 0 8v3.5a2.5 2.5 0 0 1-2.5 2.5h-19A2.5 2.5 0 0 1 3 22.5V19a4 4 0 0 0 0-8Z" fill="#00acc4"/><path d="M15 10v10M10 15h10" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg></span><span class="brand-name">Cita<span>Ya</span></span><span class="brand-context"><?= $isStaffPage || $page === 'admin-login' ? 'Acceso interno' : 'Historias clínicas' ?></span></a>
<nav class="header-nav" aria-label="Navegación principal"><a href="?page=home" <?= $isPortal ? 'aria-current="page"' : '' ?>>Solicitar historia</a><a href="?page=<?= $isStaffPage ? 'deliveries' : 'admin' ?>" <?= $isStaffPage || $page === 'admin-login' ? 'aria-current="page"' : '' ?>>Acceso interno</a></nav><?php if ($ambient || $consoleAmbient): ?><button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" hidden><span class="theme-icon-dark"><?= ui_icon('sun', 16) ?></span><span class="theme-icon-light"><?= ui_icon('moon', 16) ?></span><span data-theme-label>Modo claro</span></button><?php endif ?><?php if ($isStaffPage && !empty($_SESSION['admin_id'])): ?><form method="post" class="header-logout"><?= csrf() ?><input type="hidden" name="action" value="logout"><button title="Cerrar sesión"><?= ui_icon('logout', 18) ?><span>Cerrar sesión</span></button></form><?php endif ?></header>
<main class="shell" id="contenido">
<?php if ($isPortal): ?>
<?php if ($page !== 'home'): ?><div class="intro"><h1>Tu historia clínica, en tu correo.</h1><p>Busca la atención con tus datos y recibe el paquete completo, con sus anexos.</p></div>
<ol class="steps" aria-label="Progreso de la solicitud"><?php foreach ([1=>'Tus datos',2=>'Búsqueda',3=>'Entrega'] as $n=>$label): $state = $n < $steps[$page] ? 'done' : ($n === $steps[$page] ? 'current' : 'next'); ?><li class="<?= $state ?>" <?= $state === 'current' ? 'aria-current="step"' : '' ?>><span class="step-number"><?= $state === 'done' ? ui_icon('check', 16) . '<span class="visually-hidden">Completado: </span>' : $n ?></span><span class="step-label"><?= h($label) ?></span></li><?php endforeach ?></ol><?php else: ?><div class="request-hero"><h1>Solicitud de historia clínica</h1><p>Radica tu solicitud y recibe la historia completa de tu atención, con sus anexos, en tu correo.</p></div><?php endif ?>
<div class="patient-layout<?= $page === 'home' ? ' request-only' : '' ?>"><section class="card main-card<?= $page === 'home' ? ' request-card' : '' ?>">
<?php if ($page === 'home'): ?>
<?php require __DIR__ . '/request-form.php'; ?>
<?php elseif ($page === 'verify'): ?>
<span class="stage-icon"><?= ui_icon('mail', 26) ?></span>
<?php if ($app->config['mail_transport'] === 'local'): ?>
<h2>El envío por correo aún no está habilitado</h2><div class="notice"><?= ui_icon('info') ?><p>Esta instalación está en modo de prueba y no envía mensajes a tu correo. Primero debemos configurar el servicio de correo para recibir códigos reales.</p></div>
<?php else: ?>
<h2>Revisa el correo de tu solicitud</h2><p class="muted">Si los datos corresponden a un registro habilitado, recibirás un código. Revisa también la carpeta de correo no deseado.</p>
<?php endif ?>
<form method="post"><?= csrf() ?><input type="hidden" name="action" value="verify"><label>Código de verificación<input class="code-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus></label><small>Vence en 10 minutos. No compartas este código.</small><button class="button primary full spaced">Verificar y buscar mi atención <?= ui_icon('arrow-right') ?></button></form>
<form method="post" class="center spaced"><?= csrf() ?><input type="hidden" name="action" value="resend"><button class="text-button">Solicitar un nuevo código</button></form><a class="back-link" href="?page=home"><?= ui_icon('arrow-left', 16) ?>Volver al formulario</a>
<?php elseif ($page === 'appointments'): ?>
<h2>Selecciona tu atención</h2><p class="muted">Encontramos varias atenciones compatibles con los datos ingresados<?= $request['direct_search'] ? ' en los PDF de la clínica' : '' ?>. Elige la que necesitas.</p>
<?php if (!$encounters): ?><div class="notice"><?= ui_icon('info') ?><p>No encontramos atenciones con estos filtros. Puedes ajustar la fecha o la especialidad.</p></div><?php endif ?>
<div class="encounter-list"><?php foreach ($encounters as $encounter): ?><form method="post" class="encounter"><?= csrf() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="encounter_id" value="<?= $encounter['id'] ?>"><div class="encounter-date"><strong><?= date('d/m/Y', strtotime($encounter['attended_at'])) ?></strong></div><div class="encounter-copy"><strong><?= h($encounter['specialty']) ?></strong><p><?= h($encounter['location']) ?></p></div><button class="button secondary">Recibir historia <?= ui_icon('arrow-right', 18) ?></button></form><?php endforeach ?></div>
<a class="button secondary spaced" href="#adjust-modal" data-open="adjust-modal"><?= ui_icon('calendar', 18) ?>Ajustar fecha o especialidad</a>
<dialog <?= ui_dialog_attrs('adjust-modal', 'modal', ui_reopen('filters')) ?>><?= ui_sheet_head('adjust-modal', 'Ajustar la búsqueda', 'Cambia la fecha o la especialidad y buscaremos de nuevo.') ?><form method="post" class="sheet-body"><?= csrf() ?><input type="hidden" name="action" value="filters"><label>Fecha aproximada<input type="date" name="approximate_date" value="<?= h($_POST['approximate_date'] ?? $request['approximate_date']) ?>" max="<?= date('Y-m-d') ?>" required autofocus></label><label>Especialidad<select name="specialty_id"><option value="0">No recuerdo</option><?php foreach ($specialties as $specialty): ?><option value="<?= $specialty['id'] ?>" <?= (int) $request['specialty_id'] === (int) $specialty['id'] ? 'selected' : '' ?>><?= h($specialty['name']) ?></option><?php endforeach ?></select></label><div class="sheet-foot"><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary">Actualizar búsqueda</button></div></form></dialog>
<?php elseif ($page === 'status'): $state = $request['status'];
$tone = ui_tone($state);
$toneIcon = ['live' => 'clock', 'ok' => 'check-circle', 'stop' => 'alert', 'wait' => 'search'][$tone]; ?>
<div class="status-view" <?= $tone === 'live' ? 'data-refresh="true"' : '' ?>>
<section class="call-plate <?= $tone ?>" aria-label="Estado de la solicitud"><div class="call-number"><span class="call-label">Radicado</span><strong class="call-ref"><?= h($request['reference']) ?></strong></div><div class="call-state"><?= ui_icon($toneIcon, 22) ?><h2><?= h($labels[$state] ?? $state) ?></h2></div><?php if ($tone === 'live'): ?><span class="call-live" aria-hidden="true"></span><?php endif ?></section>
<?php $waConfirm = $_SESSION['whatsapp_confirm'] ?? null; if (empty($request['whatsapp_phone']) && !empty($request['whatsapp_claimed']) && ($waConfirm['request'] ?? null) === (int)$request['id'] && strtotime((string)$request['whatsapp_code_expires']) > time()):
$botNumber = (string)$app->config['whatsapp_bot_number']; $confirmText = 'CONFIRMAR ' . $waConfirm['code']; ?>
<div class="notice wa-confirm"><?= ui_icon('chat') ?><div><p><strong>Confirma tu WhatsApp para recibirla también por chat.</strong><br>Desde el WhatsApp <?= h(App\WhatsApp::maskPhone($request['whatsapp_claimed'])) ?> envía este mensaje<?= $botNumber !== '' ? ' al <strong>+' . h($botNumber) . '</strong>' : ' al WhatsApp de CitaYa' ?>:</p>
<p class="wa-code"><?= h($confirmText) ?></p>
<?php if ($botNumber !== ''): ?><a class="button primary" href="https://wa.me/<?= h($botNumber) ?>?text=<?= rawurlencode($confirmText) ?>" target="_blank" rel="noopener">Abrir WhatsApp y enviar el código</a><?php endif ?>
<small>El código vence en <?= max(1, intdiv((int)$app->config['whatsapp_link_seconds'], 3600)) ?> horas. Hasta que lo confirmes, no enviaremos nada a ese número.</small></div></div>
<?php endif ?>
<?php if (!empty($request['whatsapp_phone'])): ?><p class="muted with-icon"><?= ui_icon('chat', 18) ?><span>Te mantendremos informado por WhatsApp al número <?= h(App\WhatsApp::maskPhone($request['whatsapp_phone'])) ?><?= in_array($state, ['no_matches','unavailable','not_eligible'], true) ? ': te enviamos el radicado de esta solicitud.' : '.' ?></span></p><?php endif ?>
<?php if ($state === 'no_matches' && !empty($dateGiven)): ?>
<?php elseif ($state === 'no_matches'): ?>
<?php if (!empty($recentSuggestion)): ?><p class="lead"><?= $request['approximate_date'] === null ? 'Como no recuerdas la fecha, buscamos tu consulta más reciente disponible. Elígela si corresponde a la historia que necesitas.' : 'No encontramos una historia para el ' . h(date('d/m/Y', strtotime($request['approximate_date']))) . ' con los datos indicados.' ?></p>
<?php elseif (empty($request['matched_at'])): ?><p class="lead">No pudimos relacionar los datos ingresados con un paciente del repositorio. Revisa el tipo y número de documento y escribe todos los nombres y apellidos tal como aparecen en la historia.</p><p class="muted">Para recomendarte otra fecha, primero necesitamos encontrar al paciente. Comprueba también que su PDF esté cargado.</p>
<?php else: ?><p class="lead">No encontramos una historia disponible que podamos recomendar con la fecha y especialidad indicadas. Indica aquí abajo la fecha aproximada de la atención.</p><?php endif ?>
<?php elseif ($state === 'not_eligible'): ?><p class="lead">No se pudo habilitar la entrega automática al correo indicado. No se enviaron documentos.</p>
<?php elseif ($state === 'simulated'): ?><p class="lead">El correo de prueba se preparó con todos los PDF. Está disponible en la bandeja local del acceso interno; no se ha enviado a Internet.</p>
<?php elseif ($state === 'sent'): ?><p class="lead">El servidor de correo aceptó el mensaje con tu historia completa. Revisa el correo de tu solicitud y la carpeta de correo no deseado.</p>
<?php elseif ($state === 'unavailable'): ?><p class="lead">El paquete de esta atención no está disponible para entrega. No se enviaron documentos incompletos.</p><a class="button secondary" href="?page=appointments">Revisar otra atención</a>
<?php elseif ($state === 'uncertain'): ?><p class="lead">No pudimos confirmar si el servidor aceptó el mensaje. Revisa tu correo antes de hacer otra solicitud.</p>
<?php elseif ($state === 'failed'): ?><p class="lead">No fue posible completar el envío. El resultado quedó registrado para su revisión técnica.</p>
<?php else: ?><p class="lead">Estamos preparando el paquete completo. Esta página se actualizará automáticamente. Puedes cerrarla: el envío continuará.</p><?php endif ?>
<?php if (!empty($recentSuggestion)): ?>
<section class="suggestion" aria-label="Consulta más reciente disponible"><h3>¿Buscabas tu consulta del <span class="mono"><?= h(date('d/m/Y', strtotime($recentSuggestion['attended_at']))) ?></span>?</h3><p class="muted"><?= h($recentSuggestion['specialty']) ?> · <?= h($recentSuggestion['location']) ?></p><p class="muted">Encontramos una historia de esta consulta para el mismo paciente. Puedes elegirla para recibir el paquete completo en el correo de tu solicitud.</p>
<form method="post" action="?page=status"><?= csrf() ?><input type="hidden" name="action" value="select-suggestion"><input type="hidden" name="encounter_id" value="<?= (int)$recentSuggestion['id'] ?>"><button type="submit" class="button primary full">Solicitar historia del <?= h(date('d/m/Y', strtotime($recentSuggestion['attended_at']))) ?></button></form></section>
<?php endif ?>
<?php if ($state === 'no_matches' && !empty($request['matched_at'])): ?>
<?php if (!empty($dateGiven)): ?><div class="notice spaced"><?= ui_icon('clock') ?><p><strong>Solicitud registrada con la fecha aproximada <?= h(date('d/m/Y', strtotime($request['approximate_date']))) ?>.</strong><br>La persona encargada la verificará y te enviaremos la historia al correo<?= $request['whatsapp_phone'] ? ' y al WhatsApp' : '' ?> de tu solicitud. No tienes que hacer nada más.</p></div>
<?php else: ?><a class="button <?= empty($recentSuggestion) ? 'primary' : 'secondary' ?> spaced" href="#date-modal" data-open="date-modal"><?= ui_icon('calendar', 18) ?><?= !empty($recentSuggestion) ? 'No es esta consulta: indicar la fecha' : 'Indicar la fecha aproximada' ?></a>
<dialog <?= ui_dialog_attrs('date-modal', 'modal', ui_reopen('filters')) ?>><?= ui_sheet_head('date-modal', 'Fecha aproximada de la atención', 'Elige el día, mes y año que más se acerquen. Si no encontramos la historia de ese día, la solicitud queda registrada con esa fecha y la verificará la persona encargada.') ?><form method="post" action="?page=status" class="sheet-body"><?= csrf() ?><input type="hidden" name="action" value="filters"><input type="hidden" name="from" value="status"><label>Fecha aproximada de la atención<input type="date" name="approximate_date" max="<?= date('Y-m-d') ?>" value="<?= h($_POST['approximate_date'] ?? '') ?>" required autofocus></label><label>Especialidad<select name="specialty_id"><option value="0">No recuerdo</option><?php foreach ($specialties as $specialty): ?><option value="<?= $specialty['id'] ?>" <?= (int)$request['specialty_id'] === (int)$specialty['id'] ? 'selected' : '' ?>><?= h($specialty['name']) ?></option><?php endforeach ?></select></label><div class="sheet-foot"><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary">Solicitar con esta fecha</button></div></form></dialog><?php endif ?>
<?php endif ?>
<?php if ($historyResend && $historyResend['eligible'] && in_array($state, ['sent','simulated','failed','uncertain'], true)): ?>
<section class="resend" aria-label="Reenvío de historia clínica"><h3>¿No recibiste tu historia clínica?</h3><p class="muted">Revisa la carpeta de correo no deseado. Podemos reenviar el mismo paquete a <strong><?= h($request['contact_email']) ?></strong>. Si escribiste mal el correo, inicia otra solicitud.</p>
<?php if ($state === 'uncertain'): ?><p class="muted">El envío anterior podría haber llegado. Al reenviar puedes recibir una copia adicional.</p><?php endif ?>
<?php if ($historyResend['remaining'] > 0): ?>
<form method="post" action="?page=status"><?= csrf() ?><input type="hidden" name="action" value="resend-history"><input type="hidden" name="delivery_id" value="<?= (int)$historyResend['job']['id'] ?>"><button type="submit" class="button primary full" <?= $historyResend['wait'] > 0 ? 'disabled data-resend-wait="' . (int)$historyResend['wait'] . '"' : '' ?>><?= ui_icon('send') ?>Reenviar historia clínica</button></form>
<p class="muted" data-resend-countdown aria-live="polite"><?= $historyResend['wait'] > 0 ? 'Podrás reenviar en ' . (int)$historyResend['wait'] . ' segundos.' : 'Puedes solicitar el reenvío ahora.' ?></p><p class="capacity" aria-label="Reenvíos disponibles: <?= (int)$historyResend['remaining'] ?>"><span>Reenvíos disponibles</span><strong><?= (int)$historyResend['remaining'] ?></strong></p>
<?php else: ?><p class="muted">Alcanzaste el máximo de reenvíos de esta solicitud.</p><?php endif ?></section>
<?php endif ?>
<form method="post" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="new"><button class="button secondary">Hacer otra solicitud</button></form></div>
<?php endif ?>
</section><?php if ($page !== 'home'): ?><aside class="sidebar" aria-label="Ayuda para la solicitud"><div class="side-card request-help"><h3>Ayuda</h3>
<details open><summary>¿Qué incluye la historia?</summary><p>El paquete completo de una atención: notas médicas, órdenes, fórmulas y anexos.</p></details>
<details><summary>¿No recuerdas la fecha?</summary><p>Marca «No recuerdo la fecha». Te mostraremos la consulta más reciente disponible para que puedas elegirla.</p></details>
<details><summary>¿No llegó el correo?</summary><p>Revisa la carpeta de correo no deseado. En el estado de tu solicitud puedes usar «Reenviar historia clínica» cuando esté disponible.</p></details>
</div>
</aside><?php endif ?></div>
<?php elseif ($page === 'admin-login'): ?>
<?php require __DIR__ . '/login.php'; ?>
<?php elseif ($isStaffPage): ?>
<?php require __DIR__ . '/admin.php'; ?>
<?php else: http_response_code(404); ?><section class="card not-found"><h1>Página no encontrada</h1><p class="muted">La dirección no existe o cambió.</p><a class="button secondary" href="?page=home"><?= ui_icon('arrow-left', 18) ?>Volver al inicio</a></section><?php endif ?>
</main><?php if (!$isStaffPage): ?><footer class="footer"><span class="footer-brand">CitaYa</span><span>Historias clínicas · Portal del paciente</span><span><?= $app->config['mode'] === 'demo' ? 'Versión de prueba' : 'Acceso privado a tus documentos' ?></span></footer><?php endif ?>
<?php if ($page === 'status' && in_array($request['status'], ['queued','sending','retry'], true)): require __DIR__ . '/queue-progress.php'; endif ?>
<div class="toast-stack" data-toasts aria-live="polite"><?php if ($error): ?><?= ui_toast('error', 'No se pudo completar', $error) ?><?php endif ?><?php if ($flash): $tone = $flashTone ?? 'success'; ?><?= ui_toast($tone, ['success' => 'Listo', 'info' => 'Aviso', 'error' => 'No se pudo completar'][$tone] ?? 'Aviso', $flash) ?><?php endif ?></div>
<dialog id="confirm-dialog" class="modal alert-modal" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text"><div class="alert-modal-icon" data-confirm-icon><?= ui_icon('alert', 26) ?></div><h2 id="confirm-dialog-title" data-confirm-title>¿Confirmas esta acción?</h2><p id="confirm-dialog-text" data-confirm-text></p><div class="alert-modal-actions"><button type="button" class="button secondary" data-close autofocus>Cancelar</button><button type="button" class="button primary" data-confirm-accept>Confirmar</button></div></dialog>
</body></html>
