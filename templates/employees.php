<?php require_once __DIR__ . '/icons.php'; ?>
<?php
// Gestión de empleados (solo administradores, SCRUM-46): CRUD por cédula, contraseña, activación y avance.
// Crear va en drawer; editar es un formulario corto en modal (con contraseña manual opcional); la contraseña generada se avisa en un modal de una sola vez.
$newPassword = $_SESSION['staff_password'] ?? null; unset($_SESSION['staff_password']);
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$totals = ['day' => ['requests' => 0, 'reports' => 0], 'month' => ['requests' => 0, 'reports' => 0], 'year' => ['requests' => 0, 'reports' => 0]];
foreach ($staffList as $member) foreach ($totals as $range => $_) foreach (['requests', 'reports'] as $kind) $totals[$range][$kind] += $member['progress'][$range][$kind];
$roleOptions = static function (string $selected): string {
    $html = '';
    foreach (App\Staff::ROLES as $role => $label) $html .= '<option value="' . $role . '"' . ($selected === $role ? ' selected' : '') . '>' . $label . '</option>';
    return $html;
};
// Tras un error se reabre el formulario que lo produjo, con lo que se escribió.
$failedEdit = ui_reopen('staff-update') ? (int)($_POST['staff_id'] ?? 0) : 0;
$posted = static fn(string $field, string $fallback, bool $use): string => $use ? (string)($_POST[$field] ?? $fallback) : $fallback;
?>
<?php if ($newPassword): $who = (new App\Staff($app))->find((int)$newPassword['id']); ?>
<dialog id="staff-password-modal" class="modal alert-modal" aria-labelledby="staff-password-title" aria-describedby="staff-password-text" open data-auto-open data-persistent>
<div class="alert-modal-icon"><?= ui_icon('shield', 26) ?></div>
<h2 id="staff-password-title">Contraseña de <?= h($who['name'] ?? 'la cuenta') ?></h2>
<p id="staff-password-text">Cópiala y entrégasela a la persona. <strong>No se volverá a mostrar.</strong> Inicia sesión con su correo <?= h($who['email'] ?? '') ?><?= !empty($who['document_number']) ? ' (cédula ' . h($who['document_number']) . ')' : '' ?>.</p>
<p class="secret-value"><span class="mono"><?= h($newPassword['password']) ?></span><button type="button" class="copy-button" data-copy="<?= h($newPassword['password']) ?>" aria-label="Copiar contraseña" title="Copiar"><?= ui_icon('files', 16) ?></button></p>
<div class="alert-modal-actions"><a class="button primary" href="#" data-close>Ya la copié</a></div>
</dialog>
<?php endif ?>
<dl class="stats"><div class="stat"><dt>Solicitudes respondidas hoy</dt><dd><?= $totals['day']['requests'] ?></dd></div><div class="stat"><dt>En el mes</dt><dd><?= $totals['month']['requests'] ?></dd></div><div class="stat"><dt>En el año</dt><dd><?= $totals['year']['requests'] ?></dd></div><div class="stat"><dt>Reportes resueltos en el mes</dt><dd><?= $totals['month']['reports'] ?></dd></div></dl>

<section class="card"><div class="section-head"><div><h2>Personal y avance</h2><p class="muted">Solicitudes respondidas (Aprobado o No Registra) y reportes de error resueltos por cada persona.</p></div></div>
<form method="get" class="delivery-search"><input type="hidden" name="page" value="employees"><label>Buscar por cédula, nombre o correo<input type="search" name="q" value="<?= h($search) ?>" maxlength="80"></label><button class="button primary"><?= ui_icon('search', 18) ?>Buscar</button><?php if ($search !== ''): ?><a class="button secondary" href="?page=employees">Ver todos</a><?php endif ?></form>
<?php if (!$staffList): ?><div class="empty-state"><?= ui_icon('users', 28) ?><p><?= $search !== '' ? 'No hay cuentas que coincidan con la búsqueda.' : 'Todavía no hay cuentas.' ?></p></div>
<?php else: ?><div class="table-wrap"><table class="responsive-table staff-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Cédula</th><th scope="col" role="columnheader">Persona</th><th scope="col" role="columnheader">Rol</th><th scope="col" role="columnheader">Hoy</th><th scope="col" role="columnheader">Mes</th><th scope="col" role="columnheader">Año</th><th scope="col" role="columnheader">Reportes (mes)</th><th scope="col" role="columnheader">Acciones</th></tr></thead><tbody role="rowgroup">
<?php foreach ($staffList as $member): $self = (int)$member['id'] === (int)$_SESSION['admin_id']; $id = (int)$member['id']; ?>
<tr role="row" class="<?= $member['active'] ? '' : 'inactive' ?>"><td role="cell" data-label="Cédula"><strong class="mono"><?= $member['document_number'] ? h($member['document_number']) : '<span class="danger with-icon">' . ui_icon('alert', 16) . 'Sin cédula</span>' ?></strong></td>
<td role="cell" data-label="Persona"><strong><?= h($member['name']) ?></strong><small><?= h($member['email']) ?></small><?php if (!$member['active']): ?><span class="pill stop">Desactivada</span><?php endif ?><?php if ($self): ?><span class="pill info">Tu cuenta</span><?php endif ?></td>
<td role="cell" data-label="Rol"><?= h(App\Staff::ROLES[$member['role']] ?? $member['role']) ?></td>
<td role="cell" data-label="Hoy" class="num"><strong><?= $member['progress']['day']['requests'] ?></strong></td>
<td role="cell" data-label="Mes" class="num"><strong><?= $member['progress']['month']['requests'] ?></strong></td>
<td role="cell" data-label="Año" class="num"><strong><?= $member['progress']['year']['requests'] ?></strong></td>
<td role="cell" data-label="Reportes (mes)" class="num"><?= $member['progress']['month']['reports'] ?></td>
<td role="cell" data-label="Acciones"><a class="button secondary small" href="#staff-edit-<?= $id ?>" data-open="staff-edit-<?= $id ?>" aria-label="Editar la cuenta de <?= h($member['name']) ?>"><?= ui_icon('user', 16) ?>Editar</a></td></tr>
<?php endforeach ?>
</tbody></table></div><?php endif ?></section>

<?php foreach ($staffList as $member): $self = (int)$member['id'] === (int)$_SESSION['admin_id']; $id = (int)$member['id']; $reopen = $failedEdit === $id; ?>
<dialog <?= ui_dialog_attrs('staff-edit-' . $id, 'modal', $reopen) ?>>
<?= ui_sheet_head('staff-edit-' . $id, 'Editar cuenta', $member['name']) ?>
<form method="post" action="?page=employees" class="sheet-body"><?= csrf() ?><input type="hidden" name="action" value="staff-update"><input type="hidden" name="staff_id" value="<?= $id ?>">
<div class="grid"><label>Cédula<input class="mono-input" name="document_number" inputmode="numeric" value="<?= h($posted('document_number', (string)($member['document_number'] ?? ''), $reopen)) ?>" required maxlength="15" pattern="[0-9.\s-]{5,15}" autofocus></label><label>Rol<select name="role"><?= $roleOptions($posted('role', (string)$member['role'], $reopen)) ?></select></label></div>
<label>Nombre completo<input name="name" value="<?= h($posted('name', (string)$member['name'], $reopen)) ?>" required maxlength="120"></label>
<label>Correo (para iniciar sesión)<input name="email" type="email" value="<?= h($posted('email', (string)$member['email'], $reopen)) ?>" required maxlength="190"></label>
<fieldset class="password-set"><legend>Cambiar contraseña <span class="optional">(opcional)</span></legend>
<p class="field-help">Déjala en blanco para conservar la actual. Mínimo 10 caracteres.</p>
<div class="grid">
<label for="staff-pass-<?= $id ?>">Contraseña nueva<span class="input-icon has-action"><?= ui_icon('lock', 18) ?><input id="staff-pass-<?= $id ?>" type="password" name="new_password" autocomplete="new-password" minlength="10" maxlength="72" data-password-new><button type="button" class="input-action" data-toggle-password aria-controls="staff-pass-<?= $id ?>" aria-label="Mostrar contraseña" aria-pressed="false" hidden><?= ui_icon('eye', 18, 'eye-show') ?><?= ui_icon('eye-off', 18, 'eye-hide') ?></button></span></label>
<label for="staff-pass2-<?= $id ?>">Confirmar contraseña<span class="input-icon"><?= ui_icon('lock', 18) ?><input id="staff-pass2-<?= $id ?>" type="password" name="new_password_confirm" autocomplete="new-password" maxlength="72" data-password-confirm></span></label>
</div></fieldset>
<div class="sheet-foot"><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary">Guardar cambios</button></div>
</form>
<div class="sheet-extra"><h3>Más acciones</h3><div class="extra-actions">
<form method="post" action="?page=employees" data-confirm="Se generará una contraseña nueva y la actual dejará de funcionar. La verás una sola vez." data-confirm-title="¿Generar una contraseña nueva para <?= h($member['name']) ?>?" data-confirm-label="Generar contraseña"><?= csrf() ?><input type="hidden" name="action" value="staff-reset"><input type="hidden" name="staff_id" value="<?= $id ?>"><button class="button secondary small"><?= ui_icon('shield', 16) ?>Generar contraseña aleatoria</button></form>
<?php if (!$self): ?><form method="post" action="?page=employees" <?= $member['active'] ? 'data-confirm="La persona no podrá iniciar sesión hasta que actives la cuenta de nuevo." data-confirm-title="¿Desactivar la cuenta de ' . h($member['name']) . '?" data-confirm-label="Desactivar cuenta" data-confirm-tone="danger"' : '' ?>><?= csrf() ?><input type="hidden" name="action" value="staff-toggle"><input type="hidden" name="staff_id" value="<?= $id ?>"><input type="hidden" name="active" value="<?= $member['active'] ? '0' : '1' ?>"><button class="button secondary small"><?= $member['active'] ? 'Desactivar cuenta' : 'Activar cuenta' ?></button></form>
<form method="post" action="?page=employees" data-confirm="Ya no podrá iniciar sesión. Si atendió solicitudes o reportes, ese historial se conserva." data-confirm-title="¿Eliminar la cuenta de <?= h($member['name']) ?>?" data-confirm-label="Eliminar cuenta" data-confirm-tone="danger"><?= csrf() ?><input type="hidden" name="action" value="staff-delete"><input type="hidden" name="staff_id" value="<?= $id ?>"><button class="button danger-outline small">Eliminar cuenta</button></form><?php endif ?>
</div></div>
</dialog>
<?php endforeach ?>

<dialog <?= ui_dialog_attrs('staff-create-drawer', 'drawer', ui_reopen('staff-create')) ?>>
<?= ui_sheet_head('staff-create-drawer', 'Agregar empleado', 'La cédula identifica a cada persona y no se puede repetir. Se genera una contraseña aleatoria que verás una sola vez.') ?>
<form method="post" action="?page=employees" class="sheet-body"><?= csrf() ?><input type="hidden" name="action" value="staff-create">
<label>Cédula<input class="mono-input" name="document_number" inputmode="numeric" required maxlength="15" pattern="[0-9.\s-]{5,15}" autocomplete="off" placeholder="Ej.: 1234567890" value="<?= h(ui_reopen('staff-create') ? ($_POST['document_number'] ?? '') : '') ?>" autofocus></label>
<label>Nombre completo<input name="name" required maxlength="120" autocomplete="off" value="<?= h(ui_reopen('staff-create') ? ($_POST['name'] ?? '') : '') ?>"></label>
<label>Correo (para iniciar sesión)<input name="email" type="email" required maxlength="190" autocomplete="off" value="<?= h(ui_reopen('staff-create') ? ($_POST['email'] ?? '') : '') ?>"></label>
<label>Rol<select name="role"><?= $roleOptions(ui_reopen('staff-create') ? (string)($_POST['role'] ?? 'employee') : 'employee') ?></select></label>
<div class="sheet-foot"><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary"><?= ui_icon('users', 18) ?>Crear cuenta</button></div>
</form>
</dialog>
