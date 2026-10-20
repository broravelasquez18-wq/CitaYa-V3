<?php require_once __DIR__ . '/icons.php';
// Panel de avance de una persona: «Mi avance» ($panelSelf) y «Ver avance» en Empleados (modal o página).
// $panel = Staff::report(): member, progress (día, mes, año), mix (respuestas del mes por tipo) y recent.
$panelSelf ??= false;
$member = $panel['member'];
$ranges = ['day' => 'Hoy', 'month' => 'Este mes', 'year' => 'Este año'];
$resolutions = ['approved' => 'Aprobado', 'not_registered' => 'No Registra', 'assigned' => 'Historia asignada'];
$monthTotal = array_sum($panel['mix']);
?>
<span hidden data-case-title><?= h($member['name']) ?></span>
<div class="staff-progress">
<?php if (!$panelSelf): ?><header class="staff-progress-head"><span class="staff-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr(trim((string)$member['name']), 0, 1))) ?></span><div><h3><?= h($member['name']) ?></h3><p><?= h(App\Staff::ROLES[$member['role']] ?? $member['role']) ?><?= $member['document_number'] ? ' · CC <span class="mono">' . h($member['document_number']) . '</span>' : '' ?> · <?= h($member['email']) ?></p></div><?= $member['active'] ? '<span class="pill ok">Activa</span>' : '<span class="pill stop">Desactivada</span>' ?></header><?php endif ?>
<dl class="stats progress-stats"><?php foreach ($ranges as $range => $label): ?><div class="stat"><dt>Solicitudes respondidas · <?= $label ?></dt><dd><?= (int)$panel['progress'][$range]['requests'] ?></dd></div><?php endforeach ?><div class="stat"><dt>Reportes resueltos · Este mes</dt><dd><?= (int)$panel['progress']['month']['reports'] ?></dd></div></dl>
<section class="progress-mix" aria-label="Respuestas del mes por tipo"><h4>Respuestas de este mes por tipo</h4>
<?php if (!$monthTotal): ?><p class="muted"><?= $panelSelf ? 'Todavía no has respondido solicitudes este mes.' : 'No ha respondido solicitudes este mes.' ?></p>
<?php else: $offset = 0.0; // La CSP no admite estilos en línea: la barra es un SVG con anchos como atributos. ?><svg class="progress-bar" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="<?= h(implode(', ', array_map(fn($k) => $resolutions[$k] . ': ' . $panel['mix'][$k], array_keys(array_filter($panel['mix']))))) ?>"><?php foreach ($panel['mix'] as $kind => $count): if (!$count) continue; $width = $count * 100 / $monthTotal; ?><rect class="<?= $kind ?>" x="<?= round($offset, 3) ?>" y="0" width="<?= round($width, 3) ?>" height="10"/><?php $offset += $width; endforeach ?></svg>
<ul class="progress-legend"><?php foreach ($panel['mix'] as $kind => $count): if ($kind === 'assigned' && !$count) continue; ?><li><span class="dot <?= $kind ?>" aria-hidden="true"></span><?= $resolutions[$kind] ?><strong class="mono"><?= (int)$count ?></strong><small><?= $monthTotal ? round($count * 100 / $monthTotal) : 0 ?> %</small></li><?php endforeach ?></ul><?php endif ?>
</section>
<section class="progress-recent"><h4><?= $panelSelf ? 'Mis últimas respuestas' : 'Últimas respuestas' ?></h4>
<?php if (!$panel['recent']): ?><div class="empty-state"><?= ui_icon('inbox', 28) ?><p><?= $panelSelf ? 'Todavía no has respondido solicitudes. Ve a <a href="?page=deliveries">Solicitudes</a> para atender las pendientes.' : 'Esta persona todavía no ha respondido solicitudes.' ?></p></div>
<?php else: ?><div class="table-wrap"><table class="responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Radicado</th><th scope="col" role="columnheader">Paciente</th><th scope="col" role="columnheader">Respuesta</th><th scope="col" role="columnheader">Fecha</th></tr></thead><tbody role="rowgroup">
<?php foreach ($panel['recent'] as $row): ?><tr role="row"><td role="cell" data-label="Radicado"><strong class="mono ref"><?= h($row['reference']) ?></strong></td><td role="cell" data-label="Paciente"><?= h($row['claimed_name']) ?></td><td role="cell" data-label="Respuesta"><span class="pill <?= $row['resolution'] === 'not_registered' ? 'stop' : 'ok' ?>"><?= h($resolutions[$row['resolution']] ?? $row['resolution']) ?></span></td><td role="cell" data-label="Fecha" class="mono"><?= h(date('d/m/Y h:i a', strtotime($row['resolved_at']))) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section>
</div>
