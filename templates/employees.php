<?php
// Gestión de empleados (solo administradores, SCRUM-46): crear, editar, activar/desactivar, contraseña y avance.
$newPassword = $_SESSION['staff_password'] ?? null; unset($_SESSION['staff_password']);
$totals = ['day' => ['requests' => 0, 'reports' => 0], 'month' => ['requests' => 0, 'reports' => 0], 'year' => ['requests' => 0, 'reports' => 0]];
foreach ($staffList as $member) foreach ($totals as $range => $_) foreach (['requests', 'reports'] as $kind) $totals[$range][$kind] += $member['progress'][$range][$kind];
?>
<?php if ($newPassword): $who = array_values(array_filter($staffList, fn($m) => (int)$m['id'] === (int)$newPassword['id']))[0] ?? null; ?>
<div class="notice staff-password"><p><strong>Contraseña de <?= h($who['name'] ?? 'la cuenta') ?> (<?= h($who['email'] ?? '') ?>):</strong></p><p class="wa-code"><?= h($newPassword['password']) ?></p><p>Cópiala y entrégasela a la persona. No se volverá a mostrar.</p></div>
<?php endif ?>
<div class="stats"><div class="stat"><strong><?= $totals['day']['requests'] ?></strong><span>Solicitudes respondidas hoy</span></div><div class="stat"><strong><?= $totals['month']['requests'] ?></strong><span>En el mes</span></div><div class="stat"><strong><?= $totals['year']['requests'] ?></strong><span>En el año</span></div><div class="stat"><strong><?= $totals['month']['reports'] ?></strong><span>Reportes resueltos en el mes</span></div></div>
<section class="card"><h2>Personal y avance</h2><p class="muted">Solicitudes respondidas (Aprobado o No Registra) y reportes de error resueltos por cada persona: hoy, en el mes y en el año.</p>
<div class="table-wrap"><table class="responsive-table staff-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Persona</th><th scope="col" role="columnheader">Rol</th><th scope="col" role="columnheader">Hoy</th><th scope="col" role="columnheader">Mes</th><th scope="col" role="columnheader">Año</th><th scope="col" role="columnheader">Reportes (mes)</th><th scope="col" role="columnheader">Acciones</th></tr></thead><tbody role="rowgroup">
<?php foreach ($staffList as $member): $self = (int)$member['id'] === (int)$_SESSION['admin_id']; ?>
<tr role="row" class="<?= $member['active'] ? '' : 'inactive' ?>"><td role="cell" data-label="Persona"><strong><?= h($member['name']) ?></strong><small><?= h($member['email']) ?></small><?php if (!$member['active']): ?><span class="pill sla-late">Desactivada</span><?php endif ?></td>
<td role="cell" data-label="Rol"><?= h(App\Staff::ROLES[$member['role']] ?? $member['role']) ?></td>
<td role="cell" data-label="Hoy"><strong><?= $member['progress']['day']['requests'] ?></strong></td>
<td role="cell" data-label="Mes"><strong><?= $member['progress']['month']['requests'] ?></strong></td>
<td role="cell" data-label="Año"><strong><?= $member['progress']['year']['requests'] ?></strong></td>
<td role="cell" data-label="Reportes (mes)"><?= $member['progress']['month']['reports'] ?></td>
<td role="cell" data-label="Acciones"><details class="staff-actions"><summary>Gestionar</summary>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-update"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><label>Nombre<input name="name" value="<?= h($member['name']) ?>" required maxlength="120"></label><label>Rol<select name="role"><?php foreach (App\Staff::ROLES as $role => $label): ?><option value="<?= $role ?>" <?= $member['role'] === $role ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><button class="button secondary">Guardar</button></form>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-reset"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><button class="text-button">Generar nueva contraseña</button></form>
<?php if (!$self): ?><form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-toggle"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><input type="hidden" name="active" value="<?= $member['active'] ? '0' : '1' ?>"><button class="text-button <?= $member['active'] ? 'danger' : '' ?>"><?= $member['active'] ? 'Desactivar cuenta' : 'Activar cuenta' ?></button></form><?php endif ?>
</details></td></tr>
<?php endforeach ?>
</tbody></table></div></section>
<section class="card spaced"><h2>Agregar empleado</h2><p class="muted">Se genera una contraseña aleatoria que verás una sola vez. El empleado podrá responder solicitudes, atender reportes de error y ver su avance.</p>
<form method="post" action="?page=employees" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="staff-create">
<div class="grid"><label>Nombre completo<input name="name" required maxlength="120" autocomplete="off"></label><label>Correo<input name="email" type="email" required maxlength="190" autocomplete="off"></label></div>
<label>Rol<select name="role"><option value="employee" selected>Empleado</option><option value="admin">Administrador</option></select></label>
<button class="button primary">Crear cuenta</button></form></section>
