<?php
// Dashboard (antes «Análisis mensual»). Cada sección es una plantilla de templates/dashboard/ y usa lo que arma index.php:
// $analytics (mes), $trend (12 meses), $specialty (demanda por día, mes o año), $channels (canales) y $activity (totales).
// Las pruebas cargan esta plantilla con solo algunas de esas variables: cada sección aparece si tiene sus datos.
require_once __DIR__ . '/icons.php';
require __DIR__ . '/dashboard/helpers.php';
?>
<?php if (isset($trend)) require __DIR__ . '/dashboard/overview.php'; ?>
<?php require __DIR__ . '/dashboard/demand.php'; ?>
<?php if (isset($channels)): ?>
<div class="analytics-split">
<?php require __DIR__ . '/dashboard/channels.php'; ?>
</div>
<?php endif ?>
<?php if (isset($activity)) require __DIR__ . '/dashboard/activity.php'; ?>
<div class="chart-tooltip" role="status" hidden><strong></strong><span></span></div>
