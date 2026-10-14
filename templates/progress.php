<?php require_once __DIR__ . '/icons.php'; ?>
<?php
// «Mi avance» (SCRUM-46): lo que respondió la persona que inició sesión.
$labels3 = ['day' => 'Hoy', 'month' => 'Este mes', 'year' => 'Este año'];
$resolutions = ['approved' => 'Aprobado', 'not_registered' => 'No Registra', 'assigned' => 'Historia asignada'];
?>
<dl class="stats progress-stats"><?php foreach ($labels3 as $range => $label): ?><div class="stat"><dt>Solicitudes respondidas · <?= $label ?></dt><dd><?= (int)$myProgress['progress'][$range]['requests'] ?></dd></div><?php endforeach ?><div class="stat"><dt>Reportes resueltos · Este mes</dt><dd><?= (int)$myProgress['progress']['month']['reports'] ?></dd></div></dl>
<section class="card"><h2>Mis últimas respuestas</h2>
<?php if (!$myProgress['recent']): ?><div class="empty-state"><?= ui_icon('inbox', 28) ?><p>Todavía no has respondido solicitudes. Ve a <a href="?page=deliveries">Solicitudes</a> para atender las pendientes.</p></div>
<?php else: ?><div class="table-wrap"><table class="responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Radicado</th><th scope="col" role="columnheader">Paciente</th><th scope="col" role="columnheader">Respuesta</th><th scope="col" role="columnheader">Fecha</th></tr></thead><tbody role="rowgroup">
<?php foreach ($myProgress['recent'] as $row): ?><tr role="row"><td role="cell" data-label="Radicado"><strong class="mono ref"><?= h($row['reference']) ?></strong></td><td role="cell" data-label="Paciente"><?= h($row['claimed_name']) ?></td><td role="cell" data-label="Respuesta"><span class="pill <?= $row['resolution'] === 'not_registered' ? 'stop' : 'ok' ?>"><?= h($resolutions[$row['resolution']] ?? $row['resolution']) ?></span></td><td role="cell" data-label="Fecha" class="mono"><?= h(date('d/m/Y h:i a', strtotime($row['resolved_at']))) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section>
