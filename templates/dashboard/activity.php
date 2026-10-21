<?php
// Totales de solicitudes y envíos del día elegido, de su mes y de su año.
$activityDate = new DateTimeImmutable($activity['date']);
$periodLabels = ['day' => ['Día', $activityDate->format('d/m/Y')], 'month' => ['Mes', $monthNames[$activityDate->format('m')] . ' de ' . $activityDate->format('Y')], 'year' => ['Año', $activityDate->format('Y')]];
$activityRows = [['requests', 'Solicitudes recibidas', ''], ['sent', 'Envíos reales', ''], ['simulated', 'Envíos simulados', 'activity-simulated']];
?>
<section class="card activity-card" aria-labelledby="activity-title">
<header class="analytics-heading">
<div><h2 id="activity-title">Totales de solicitudes y envíos</h2><p class="muted">Consulta el día seleccionado, su mes completo y su año completo.</p></div>
<form method="get" class="analytics-filter"><?= $keepFilters(['month' => $analytics['month']]) ?><label>Fecha de referencia<input type="date" name="activity_date" min="1000-01-01" max="9998-12-31" value="<?= h($activity['date']) ?>" required></label><button class="button primary">Consultar totales</button></form>
</header>

<div class="activity-grid">
<?php foreach ($periodLabels as $key => [$title, $label]): $values = $activity['totals'][$key]; ?>
<article class="activity-period">
<h3><span class="period-kind"><?= h($title) ?></span> <span class="period-value"><?= h($label) ?></span></h3>
<dl>
<?php foreach ($activityRows as [$field, $name, $class]): ?>
<div<?= $class ? ' class="' . $class . '"' : '' ?>><dt><?= $name ?></dt><dd><?= $fmt($values[$field]) ?></dd></div>
<?php endforeach ?>
</dl>
</article>
<?php endforeach ?>
</div>
<p class="muted">Las solicitudes se cuentan por su fecha de creación, cualquiera que sea su estado. Los envíos se cuentan cuando el servidor de correo acepta la historia, incluidos los reenvíos; se excluyen códigos de verificación, pendientes y fallidos. Un envío no confirma lectura ni recepción en la bandeja del paciente.</p>
<small>Fechas en hora de Colombia. Cada correo cuenta una vez, aunque contenga varios PDF. Las simulaciones se muestran por separado.</small>
</section>
