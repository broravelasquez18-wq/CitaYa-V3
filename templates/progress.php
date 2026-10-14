<?php
// «Mi avance» (SCRUM-46): lo que respondió la persona que inició sesión.
$labels3 = ['day' => 'Hoy', 'month' => 'Este mes', 'year' => 'Este año'];
$resolutions = ['approved' => 'Aprobado', 'not_registered' => 'No Registra', 'assigned' => 'Historia asignada'];
?>
<div class="stats progress-stats"><?php foreach ($labels3 as $range => $label): ?><div class="stat"><strong><?= (int)$myProgress['progress'][$range]['requests'] ?></strong><span>Solicitudes respondidas · <?= $label ?></span></div><?php endforeach ?><div class="stat"><strong><?= (int)$myProgress['progress']['month']['reports'] ?></strong><span>Reportes resueltos · Este mes</span></div></div>
<section class="card"><h2>Mis últimas respuestas</h2>
<?php if (!$myProgress['recent']): ?><div class="empty-state">Todavía no has respondido solicitudes. Ve a <a href="?page=deliveries">Solicitudes</a> para atender las pendientes.</div>
<?php else: ?><div class="table-wrap"><table class="responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Radicado</th><th scope="col" role="columnheader">Paciente</th><th scope="col" role="columnheader">Respuesta</th><th scope="col" role="columnheader">Fecha</th></tr></thead><tbody role="rowgroup">
<?php foreach ($myProgress['recent'] as $row): ?><tr role="row"><td role="cell" data-label="Radicado"><?= h($row['reference']) ?></td><td role="cell" data-label="Paciente"><?= h($row['claimed_name']) ?></td><td role="cell" data-label="Respuesta"><span class="pill"><?= h($resolutions[$row['resolution']] ?? $row['resolution']) ?></span></td><td role="cell" data-label="Fecha"><?= h(date('d/m/Y h:i a', strtotime($row['resolved_at']))) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section>
