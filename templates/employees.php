<?php
// Gestión de empleados (solo administradores, SCRUM-46): CRUD por cédula, contraseña, activación y avance.
$newPassword = $_SESSION['staff_password'] ?? null; unset($_SESSION['staff_password']);
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$totals = ['day' => ['requests' => 0, 'reports' => 0], 'month' => ['requests' => 0, 'reports' => 0], 'year' => ['requests' => 0, 'reports' => 0]];
foreach ($staffList as $member) foreach ($totals as $range => $_) foreach (['requests', 'reports'] as $kind) $totals[$range][$kind] += $member['progress'][$range][$kind];
$roleOptions = static function (string $selected): string {
    $html = '';
    foreach (App\Staff::ROLES as $role => $label) $html .= '<option value="' . $role . '"' . ($selected === $role ? ' selected' : '') . '>' . $label . '</option>';
    return $html;
};
?>
<?php if ($newPassword): $who = (new App\Staff($app))->find((int)$newPassword['id']); ?>
<div class="notice staff-password"><p><strong>Contraseña de <?= h($who['name'] ?? 'la cuenta') ?> (cédula <?= h($who['document_number'] ?? '—') ?>, correo <?= h($who['email'] ?? '') ?>):</strong></p><p class="wa-code"><?= h($newPassword['password']) ?></p><p>Cópiala y entrégasela a la persona. No se volverá a mostrar. Inicia sesión con su correo y esta contraseña.</p></div>
<?php endif ?>
<div class="stats"><div class="stat"><strong><?= $totals['day']['requests'] ?></strong><span>Solicitudes respondidas hoy</span></div><div class="stat"><strong><?= $totals['month']['requests'] ?></strong><span>En el mes</span></div><div class="stat"><strong><?= $totals['year']['requests'] ?></strong><span>En el año</span></div><div class="stat"><strong><?= $totals['month']['reports'] ?></strong><span>Reportes resueltos en el mes</span></div></div>

<section class="card"><h2>Agregar empleado</h2><p class="muted">La cédula identifica a cada persona y no se puede repetir. Se genera una contraseña aleatoria que verás una sola vez.</p>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-create">
<div class="grid"><label>Cédula<input name="document_number" inputmode="numeric" required maxlength="15" pattern="[0-9.\s-]{5,15}" autocomplete="off" placeholder="Ej.: 1234567890"></label><label>Nombre completo<input name="name" required maxlength="120" autocomplete="off"></label></div>
<div class="grid"><label>Correo (para iniciar sesión)<input name="email" type="email" required maxlength="190" autocomplete="off"></label><label>Rol<select name="role"><?= $roleOptions('employee') ?></select></label></div>
<button class="button primary">Crear cuenta</button></form></section>

<section class="card spaced"><h2>Personal y avance</h2><p class="muted">Solicitudes respondidas (Aprobado o No Registra) y reportes de error resueltos por cada persona: hoy, en el mes y en el año.</p>
<form method="get" class="delivery-search spaced"><input type="hidden" name="page" value="employees"><label>Buscar por cédula, nombre o correo<input name="q" value="<?= h($search) ?>" maxlength="80"></label><button class="button primary">Buscar</button><?php if ($search !== ''): ?><a class="button secondary" href="?page=employees">Ver todos</a><?php endif ?></form>
<?php if (!$staffList): ?><div class="empty-state"><?= $search !== '' ? 'No hay cuentas que coincidan con la búsqueda.' : 'Todavía no hay cuentas.' ?></div>
<?php else: ?><div class="table-wrap"><table class="responsive-table staff-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Cédula</th><th scope="col" role="columnheader">Persona</th><th scope="col" role="columnheader">Rol</th><th scope="col" role="columnheader">Hoy</th><th scope="col" role="columnheader">Mes</th><th scope="col" role="columnheader">Año</th><th scope="col" role="columnheader">Reportes (mes)</th><th scope="col" role="columnheader">Acciones</th></tr></thead><tbody role="rowgroup">
<?php foreach ($staffList as $member): $self = (int)$member['id'] === (int)$_SESSION['admin_id']; ?>
<tr role="row" class="<?= $member['active'] ? '' : 'inactive' ?>"><td role="cell" data-label="Cédula"><strong><?= $member['document_number'] ? h($member['document_number']) : '<span class="danger">Sin cédula</span>' ?></strong></td>
<td role="cell" data-label="Persona"><strong><?= h($member['name']) ?></strong><small><?= h($member['email']) ?></small><?php if (!$member['active']): ?><span class="pill sla-late">Desactivada</span><?php endif ?><?php if ($self): ?><span class="pill">Tu cuenta</span><?php endif ?></td>
<td role="cell" data-label="Rol"><?= h(App\Staff::ROLES[$member['role']] ?? $member['role']) ?></td>
<td role="cell" data-label="Hoy"><strong><?= $member['progress']['day']['requests'] ?></strong></td>
<td role="cell" data-label="Mes"><strong><?= $member['progress']['month']['requests'] ?></strong></td>
<td role="cell" data-label="Año"><strong><?= $member['progress']['year']['requests'] ?></strong></td>
<td role="cell" data-label="Reportes (mes)"><?= $member['progress']['month']['reports'] ?></td>
<td role="cell" data-label="Acciones"><details class="staff-actions"><summary>Gestionar</summary>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-update"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>">
<label>Cédula<input name="document_number" inputmode="numeric" value="<?= h($member['document_number'] ?? '') ?>" required maxlength="15" pattern="[0-9.\s-]{5,15}"></label><label>Nombre<input name="name" value="<?= h($member['name']) ?>" required maxlength="120"></label><label>Correo<input name="email" type="email" value="<?= h($member['email']) ?>" required maxlength="190"></label><label>Rol<select name="role"><?= $roleOptions($member['role']) ?></select></label><button class="button secondary">Guardar cambios</button></form>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-reset"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><button class="text-button">Generar nueva contraseña</button></form>
<?php if (!$self): ?><form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-toggle"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><input type="hidden" name="active" value="<?= $member['active'] ? '0' : '1' ?>"><button class="text-button"><?= $member['active'] ? 'Desactivar cuenta' : 'Activar cuenta' ?></button></form>
<form method="post" action="?page=employees" class="spaced" data-confirm="¿Eliminar la cuenta de <?= h($member['name']) ?>? Ya no podrá iniciar sesión. Si atendió solicitudes o reportes, ese historial se conserva."><?= csrf() ?><input type="hidden" name="action" value="staff-delete"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><button class="text-button danger">Eliminar cuenta</button></form><?php endif ?>
</details></td></tr>
<?php endforeach ?>
</tbody></table></div><?php endif ?></section>
