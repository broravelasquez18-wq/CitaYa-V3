<?php
// Resumen del mes: tres indicadores frente al mes anterior y la gráfica de crecimiento de los últimos 12 meses.
$current = end($trend);
$previous = count($trend) > 1 ? $trend[count($trend) - 2] : null;
$kpis = [['requests', 'Solicitudes recibidas', 'inbox'], ['sent', 'Historias enviadas', 'send'], ['attentions', 'Atenciones del mes', 'file']];

// Geometría de la gráfica (solicitudes y envíos en un solo eje): escala «redonda» de 4 marcas.
$w = 720; $hgt = 240; $left = 44; $right = 16; $top = 16; $bottom = 32;
$maxValue = max(1, max(array_column($trend, 'requests')), max(array_column($trend, 'sent')));
$step = max(1, (int)ceil($maxValue / 4));
$magnitude = 10 ** max(0, (int)floor(log10($step)));
foreach ([1, 2, 5, 10] as $nice) if ($nice * $magnitude >= $step) { $step = $nice * $magnitude; break; }
$yMax = $step * 4;
$count = count($trend);
$last = $count - 1;
$x = static fn(int $i) => $left + ($count > 1 ? $i * ($w - $left - $right) / ($count - 1) : 0);
$y = static fn(int $v) => $top + ($hgt - $top - $bottom) * (1 - $v / $yMax);
$line = static fn(string $key) => implode(' ', array_map(fn($i) => number_format($x($i), 1, '.', '') . ',' . number_format($y($trend[$i][$key]), 1, '.', ''), array_keys($trend)));
// Datos para el aviso de app.js al recorrer la gráfica.
$growth = ['points' => array_map(fn($p) => ['label' => $monthNames[substr($p['month'], 5, 2)] . ' ' . substr($p['month'], 0, 4), 'requests' => $p['requests'], 'sent' => $p['sent']], $trend),
    'x' => array_map(fn($i) => round($x($i), 1), array_keys($trend)), 'width' => $w];
?>
<section class="card analytics-overview" aria-labelledby="overview-title">
<header class="analytics-heading">
<div><h2 id="overview-title">Resumen de <?= h($monthLabel) ?></h2><p class="muted">Cómo va el mes y cómo ha crecido el servicio en el último año.</p></div>
<form method="get" class="analytics-filter"><?= $keepFilters(['activity_date' => $activity['date'] ?? null]) ?><label>Mes<input type="month" name="month" min="1000-01" max="9998-12" value="<?= h($analytics['month']) ?>" required></label><button class="button primary">Consultar</button></form>
</header>

<dl class="kpi-row">
<?php foreach ($kpis as [$key, $label, $iconName]): $change = $delta($current[$key], $previous[$key] ?? 0); ?>
<div class="kpi"><dt><?= ui_icon($iconName, 16) ?><?= h($label) ?></dt><dd><span class="kpi-value"><?= $fmt($current[$key]) ?></span><?php if ($change): ?><span class="kpi-delta <?= $change[0] ?>"><?= ['up' => '↑', 'down' => '↓', 'flat' => '→'][$change[0]] ?> <?= h($change[1]) ?></span><?php endif ?></dd></div>
<?php endforeach ?>
</dl>

<figure class="growth-chart">
<figcaption><span class="chart-title">Crecimiento en los últimos 12 meses</span><span class="chart-legend"><span class="legend-line sc1">Solicitudes recibidas</span><span class="legend-line sc2">Historias enviadas</span></span></figcaption>
<div class="chart-frame" data-growth='<?= h(json_encode($growth, JSON_UNESCAPED_UNICODE)) ?>'>
<svg viewBox="0 0 <?= $w ?> <?= $hgt ?>" role="img" aria-label="Solicitudes y envíos por mes, últimos 12 meses. Los valores están en la tabla de abajo.">
<?php for ($t = 0; $t <= 4; $t++): $value = $step * $t; $ty = $y($value); ?>
<line class="grid" x1="<?= $left ?>" x2="<?= $w - $right ?>" y1="<?= $ty ?>" y2="<?= $ty ?>"/><text class="tick" x="<?= $left - 8 ?>" y="<?= $ty + 4 ?>" text-anchor="end"><?= $fmt($value) ?></text>
<?php endfor ?>
<?php foreach ($trend as $i => $p): $mm = substr($p['month'], 5, 2); ?>
<text class="tick" x="<?= $x($i) ?>" y="<?= $hgt - 10 ?>" text-anchor="middle"><?= $monthShort[$mm] ?><?= $mm === '01' || $i === 0 ? ' ' . substr($p['month'], 2, 2) : '' ?></text>
<?php endforeach ?>
<polyline class="series-line sc1" points="<?= $line('requests') ?>"/>
<polyline class="series-line sc2" points="<?= $line('sent') ?>"/>
<?php foreach (['requests' => 'sc1', 'sent' => 'sc2'] as $key => $color): ?>
<circle class="series-dot <?= $color ?>" cx="<?= $x($last) ?>" cy="<?= $y($trend[$last][$key]) ?>" r="4.5"/>
<?php endforeach ?>
<line class="crosshair" x1="0" x2="0" y1="<?= $top ?>" y2="<?= $hgt - $bottom ?>" />
<rect class="hit-area" x="<?= $left ?>" y="0" width="<?= $w - $left - $right ?>" height="<?= $hgt - $bottom ?>" tabindex="0" aria-label="Recorre los meses con las flechas"/>
</svg>
</div>
<details class="chart-table">
<summary>Ver los datos por mes</summary>
<div class="table-wrap"><table class="analytics-table">
<thead><tr><th scope="col">Mes</th><th scope="col">Solicitudes</th><th scope="col">Enviadas</th><th scope="col">Atenciones</th></tr></thead>
<tbody>
<?php foreach (array_reverse($trend) as $p): ?>
<tr><th scope="row"><?= h(ucfirst($monthNames[substr($p['month'], 5, 2)]) . ' ' . substr($p['month'], 0, 4)) ?></th><td class="num"><?= $fmt($p['requests']) ?></td><td class="num"><?= $fmt($p['sent']) ?></td><td class="num"><?= $fmt($p['attentions']) ?></td></tr>
<?php endforeach ?>
</tbody>
</table></div>
</details>
</figure>
</section>
