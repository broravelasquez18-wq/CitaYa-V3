<?php require_once __DIR__ . '/icons.php'; ?>
<?php
$monthNames = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
$monthLabel = $monthNames[substr($analytics['month'],5,2)] . ' de ' . substr($analytics['month'],0,4);
?>
<?php if (isset($activity)): require __DIR__ . '/activity-totals.php'; endif ?>
<section class="card analytics-card">
<div class="analytics-heading"><div><h2>Atenciones por especialidad</h2><p class="muted">Distribución de las atenciones registradas en las historias clínicas cargadas.</p></div>
<form method="get" class="analytics-filter"><input type="hidden" name="page" value="analytics"><?php if (isset($activity)): ?><input type="hidden" name="activity_date" value="<?= h($activity['date']) ?>"><?php endif ?><label>Mes de atención<input type="month" name="month" min="1000-01" max="9998-12" value="<?= h($analytics['month']) ?>" required></label><button class="button primary">Consultar</button></form></div>
<p class="muted">Se usa la fecha de atención extraída del PDF. Cada atención cuenta una vez, aunque tenga varios anexos o versiones. Incluye historias retiradas; no mide solicitudes de correo ni citas pendientes.</p>
<?php if (!$analytics['total']): ?>
<div class="empty-state"><?= ui_icon('chart', 28) ?><h3>No hay atenciones registradas en <?= h($monthLabel) ?></h3><p>Selecciona otro mes o carga las historias clínicas correspondientes.</p></div>
<?php else: ?>
<p class="result-count"><strong class="mono"><?= (int)$analytics['total'] ?></strong> atenciones en <?= h($monthLabel) ?> · <?= count($analytics['rows']) ?> especialidades</p>
<div class="table-wrap"><table class="analytics-table"><caption>Especialidades ordenadas de mayor a menor cantidad de atenciones. Porcentajes redondeados a un decimal.</caption><thead><tr><th scope="col">Especialidad</th><th scope="col">Atenciones</th><th scope="col">Porcentaje</th><th scope="col" class="bar-col"><span class="visually-hidden">Participación</span></th></tr></thead><tbody>
<?php $top=max(array_column($analytics['rows'],'percentage')) ?: 1; foreach($analytics['rows'] as $row): ?><tr><th scope="row"><?= h($row['name']) ?></th><td class="num"><?= $row['total'] ?></td><td class="num"><strong><?= number_format($row['percentage'],1,',','.') ?>%</strong></td><td class="bar-col"><svg class="share-bar" width="100%" height="10" aria-hidden="true" focusable="false"><rect class="share-track" width="100%" height="10" rx="2"/><rect class="share-fill" width="<?= number_format(100*$row['percentage']/$top,2,'.','') ?>%" height="10" rx="2"/></svg></td></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?></section>
