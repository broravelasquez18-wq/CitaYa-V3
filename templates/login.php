<?php require_once __DIR__ . '/icons.php';
// Inicio de sesión del personal: a la izquierda la marca y lo que hace CitaYa; a la derecha la tarjeta de acceso.
// Lo que hace CitaYa, de la carga del PDF a la entrega al paciente.
$modules = [
    ['files', 'Lee los PDF de la clínica', 'Datos de cada atención'],
    ['search', 'Encuentra la historia', 'Por documento, nombre y fecha'],
    ['mail', 'Envía por correo', 'Historia completa con anexos'],
    ['chat', 'Avisa por WhatsApp', 'Radicado · Estado · Entrega'],
    ['inbox', 'Organiza lo pendiente', 'Aprobado o No Registra'],
    ['chart', 'Mide la gestión', 'Avance · Dashboard'],
];
?>
<div class="login-shell">
<section class="login-brand" aria-labelledby="login-brand-title">
<div class="login-identity">
<span class="login-emblem" aria-hidden="true"><?= ui_icon('plus', 26) ?></span>
<div><h1 id="login-brand-title">Cita<span>Ya</span> <small>Acceso interno</small></h1>
<p class="login-kicker">Repositorio clínico · Historias por correo y WhatsApp</p></div>
</div>
<p class="login-lead">Carga las historias clínicas, atiende las solicitudes de los pacientes y responde sus reportes desde un solo lugar.</p>
<div class="login-modules-head"><span>Qué hace CitaYa</span><span>Del PDF al paciente</span></div>
<ul class="login-modules">
<?php foreach ($modules as [$icon, $title, $detail]): ?><li><span class="login-module-icon" aria-hidden="true"><?= ui_icon($icon, 18) ?></span><span><strong><?= h($title) ?></strong><small><?= h($detail) ?></small></span></li><?php endforeach ?>
</ul>
</section>

<section class="login-panel" aria-labelledby="login-title">
<div class="login-panel-top"><span class="login-status">Personal clínico</span></div>
<h2 id="login-title">Iniciar <span>sesión</span></h2>
<p class="login-sub">Usa el correo y la contraseña de tu cuenta.</p>
<form method="post" class="login-form"><?= csrf() ?><input type="hidden" name="action" value="login">
<label for="login-email">Correo electrónico</label>
<div class="input-icon"><?= ui_icon('mail', 18) ?><input id="login-email" type="email" name="email" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= h($_POST['email'] ?? '') ?>" required <?= empty($_POST['email']) ? 'autofocus' : '' ?>></div>
<label for="login-password">Contraseña</label>
<div class="input-icon has-action"><?= ui_icon('lock', 18) ?><input id="login-password" type="password" name="password" autocomplete="current-password" required <?= !empty($_POST['email']) ? 'autofocus' : '' ?>><button type="button" class="input-action" data-toggle-password aria-controls="login-password" aria-label="Mostrar contraseña" aria-pressed="false" hidden><?= ui_icon('eye', 18, 'eye-show') ?><?= ui_icon('eye-off', 18, 'eye-hide') ?></button></div>
<button class="button login-submit" type="submit">Acceder al portal <?= ui_icon('arrow-right', 20) ?></button>
</form>
<div class="login-help"><?= ui_icon('shield', 20) ?><p><strong>¿Olvidaste tu contraseña?</strong> Pídele a un administrador que genere una nueva desde Empleados.</p></div>
</section>
</div>
