<?php require_once __DIR__ . '/icons.php'; ?>
<?php
$monthNames = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
$monthShort = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
$monthLabel = $monthNames[substr($analytics['month'],5,2)] . ' de ' . substr($analytics['month'],0,4);
$fmt = static fn($n) => number_format((float)$n, 0, ',', '.');
$pct = static fn($n) => number_format((float)$n, 1, ',', '.') . '%';
// Variación frente al mes anterior; si el mes anterior fue 0 no hay base para un porcentaje y se muestra la cifra.
$delta = static function (int $now, int $before): ?array {
    if ($before === 0) return $now === 0 ? ['flat', 'Igual que el mes anterior'] : ['up', '+' . number_format($now, 0, ',', '.') . ' (el mes anterior no hubo)'];
    $change = ($now - $before) / $before * 100;
    if (abs($change) < 0.05) return ['flat', 'Igual que el mes anterior'];
    return [$change > 0 ? 'up' : 'down', ($change > 0 ? '+' : '−') . number_format(abs($change), 1, ',', '.') . '% vs. mes anterior'];
};
// Donut: segmentos con pathLength=100; 0,6 de separación entre segmentos (el espacio en color de superficie).
$donut = static function (array $parts, string $label): string {
    $total = array_sum(array_column($parts, 'value'));
    $svg = '<svg class="donut" viewBox="0 0 160 160" role="img" aria-label="' . h($label) . '"><circle class="donut-track" cx="80" cy="80" r="62" pathLength="100"/>';
    $offset = 0; $gap = count(array_filter($parts, fn($p) => $p['value'] > 0)) > 1 ? 0.6 : 0;
    foreach ($parts as $part) {
        if ($part['value'] <= 0) continue;
        $share = $part['value'] / $total * 100;
        $length = max(0.2, $share - $gap);
        $svg .= '<circle class="donut-segment ' . $part['color'] . '" cx="80" cy="80" r="62" pathLength="100" stroke-dasharray="' . number_format($length, 3, '.', '') . ' ' . number_format(100 - $length, 3, '.', '')
            . '" stroke-dashoffset="' . number_format(-$offset, 3, '.', '') . '" tabindex="0" data-tip="' . h($part['label']) . '" data-tip-value="' . h(number_format($part['value'], 0, ',', '.') . ' · ' . number_format($share, 1, ',', '.') . '%') . '"/>';
        $offset += $share;
    }
    return $svg . '</svg>';
};
$colors = ['sc1', 'sc2', 'sc3', 'sc4', 'sc5'];
$current = isset($trend) ? end($trend) : null;
$previous = isset($trend) && count($trend) > 1 ? $trend[count($trend) - 2] : null;
?>
<?php if (isset($trend)): ?>
<section class="card analytics-overview" aria-labelledby="overview-title">
<div class="analytics-heading"><div><h2 id="overview-title">Resumen de <?= h($monthLabel) ?></h2><p class="muted">Cómo va el mes y cómo ha crecido el servicio en el último año.</p></div>
<form method="get" class="analytics-filter"><input type="hidden" name="page" value="analytics"><?php if (isset($activity)): ?><input type="hidden" name="activity_date" value="<?= h($activity['date']) ?>"><?php endif ?><label>Mes<input type="month" name="month" min="1000-01" max="9998-12" value="<?= h($analytics['month']) ?>" required></label><button class="button primary">Consultar</button></form></div>
<dl class="kpi-row">
<?php foreach ([['requests', 'Solicitudes recibidas', 'inbox'], ['sent', 'Historias enviadas', 'send'], ['attentions', 'Atenciones del mes', 'file']] as [$key, $label, $iconName]): $change = $delta($current[$key], $previous[$key] ?? 0); ?>
<div class="kpi"><dt><?= ui_icon($iconName, 16) ?><?= h($label) ?></dt><dd><span class="kpi-value"><?= $fmt($current[$key]) ?></span><?php if ($change): ?><span class="kpi-delta <?= $change[0] ?>"><?= $change[0] === 'up' ? '↑' : ($change[0] === 'down' ? '↓' : '→') ?> <?= h($change[1]) ?></span><?php endif ?></dd></div>
<?php endforeach ?>
</dl>
<?php
// Gráfica de crecimiento (12 meses): solicitudes y envíos en un solo eje.
$w = 720; $hgt = 240; $left = 44; $right = 16; $top = 16; $bottom = 32;
$maxValue = max(1, max(array_column($trend, 'requests')), max(array_column($trend, 'sent')));
$step = max(1, (int)ceil($maxValue / 4)); $magnitude = 10 ** max(0, (int)floor(log10($step)));
foreach ([1, 2, 5, 10] as $nice) if ($nice * $magnitude >= $step) { $step = $nice * $magnitude; break; }
$yMax = $step * 4; $count = count($trend);
$x = static fn(int $i) => $left + ($count > 1 ? $i * ($w - $left - $right) / ($count - 1) : 0);
$y = static fn(int $v) => $top + ($hgt - $top - $bottom) * (1 - $v / $yMax);
$line = static fn(string $key) => implode(' ', array_map(fn($i) => number_format($x($i), 1, '.', '') . ',' . number_format($y($trend[$i][$key]), 1, '.', ''), array_keys($trend)));
$points = array_map(fn($p) => ['label' => $monthNames[substr($p['month'], 5, 2)] . ' ' . substr($p['month'], 0, 4), 'requests' => $p['requests'], 'sent' => $p['sent']], $trend);
?>
<figure class="growth-chart">
<figcaption><span class="chart-title">Crecimiento en los últimos 12 meses</span><span class="chart-legend"><span class="legend-line sc1">Solicitudes recibidas</span><span class="legend-line sc2">Historias enviadas</span></span></figcaption>
<div class="chart-frame" data-growth='<?= h(json_encode(['points' => $points, 'x' => array_map(fn($i) => round($x($i), 1), array_keys($trend)), 'width' => $w], JSON_UNESCAPED_UNICODE)) ?>'>
<svg viewBox="0 0 <?= $w ?> <?= $hgt ?>" role="img" aria-label="Solicitudes y envíos por mes, últimos 12 meses. Los valores están en la tabla de abajo.">
<?php for ($t = 0; $t <= 4; $t++): $value = $step * $t; $ty = $y($value); ?><line class="grid" x1="<?= $left ?>" x2="<?= $w - $right ?>" y1="<?= $ty ?>" y2="<?= $ty ?>"/><text class="tick" x="<?= $left - 8 ?>" y="<?= $ty + 4 ?>" text-anchor="end"><?= $fmt($value) ?></text><?php endfor ?>
<?php foreach ($trend as $i => $p): $mm = substr($p['month'], 5, 2); ?><text class="tick" x="<?= $x($i) ?>" y="<?= $hgt - 10 ?>" text-anchor="middle"><?= $monthShort[$mm] ?><?= $mm === '01' || $i === 0 ? ' ' . substr($p['month'], 2, 2) : '' ?></text><?php endforeach ?>
<polyline class="series-line sc1" points="<?= $line('requests') ?>"/>
<polyline class="series-line sc2" points="<?= $line('sent') ?>"/>
<?php $last = $count - 1; foreach (['requests' => 'sc1', 'sent' => 'sc2'] as $key => $color): ?><circle class="series-dot <?= $color ?>" cx="<?= $x($last) ?>" cy="<?= $y($trend[$last][$key]) ?>" r="4.5"/><?php endforeach ?>
<line class="crosshair" x1="0" x2="0" y1="<?= $top ?>" y2="<?= $hgt - $bottom ?>" />
<rect class="hit-area" x="<?= $left ?>" y="0" width="<?= $w - $left - $right ?>" height="<?= $hgt - $bottom ?>" tabindex="0" aria-label="Recorre los meses con las flechas"/>
</svg>
</div>
<details class="chart-table"><summary>Ver los datos por mes</summary><div class="table-wrap"><table class="analytics-table"><thead><tr><th scope="col">Mes</th><th scope="col">Solicitudes</th><th scope="col">Enviadas</th><th scope="col">Atenciones</th></tr></thead><tbody>
<?php foreach (array_reverse($trend) as $p): ?><tr><th scope="row"><?= h(ucfirst($monthNames[substr($p['month'], 5, 2)]) . ' ' . substr($p['month'], 0, 4)) ?></th><td class="num"><?= $fmt($p['requests']) ?></td><td class="num"><?= $fmt($p['sent']) ?></td><td class="num"><?= $fmt($p['attentions']) ?></td></tr><?php endforeach ?>
</tbody></table></div></details>
</figure>
</section>
<?php endif ?>

<?php
// Especialidad con más demanda: por día, mes o año (lo trae index.php; las pruebas solo pasan el mes).
$sp = $specialty ?? ['period' => 'month', 'date' => $analytics['month'] . '-01', 'start' => $analytics['month'] . '-01', 'total' => $analytics['total'], 'rows' => $analytics['rows'],
    'top' => $analytics['rows'] ? array_values(array_filter($analytics['rows'], fn($r) => $r['total'] === $analytics['rows'][0]['total'])) : []];
$spStart = new DateTimeImmutable($sp['start']);
$spLabel = match ($sp['period']) {
    'day' => 'el ' . (int)$spStart->format('j') . ' de ' . $monthNames[$spStart->format('m')] . ' de ' . $spStart->format('Y'),
    'month' => $monthNames[$spStart->format('m')] . ' de ' . $spStart->format('Y'),
    default => 'el año ' . $spStart->format('Y'),
};
$spQuery = static fn(array $extra) => '?' . http_build_query(['page' => 'analytics', 'month' => $analytics['month']] + (isset($activity) ? ['activity_date' => $activity['date']] : []) + $extra);
?>
<section class="card analytics-card demand-card" aria-labelledby="specialty-title">
<div class="analytics-heading"><div><h2 id="specialty-title">¿Qué especialidad tuvo más demanda?</h2><p class="muted">Atenciones registradas en las historias clínicas cargadas, por la fecha de atención del PDF.</p></div>
<div class="demand-filter"><nav class="segmented" aria-label="Período"><?php foreach (['day' => 'Día', 'month' => 'Mes', 'year' => 'Año'] as $key => $name): ?><a href="<?= h($spQuery(['sp' => $key, 'sp_date' => $sp['date']])) ?>#specialty-title" <?= $sp['period'] === $key ? 'aria-current="page"' : '' ?>><?= $name ?></a><?php endforeach ?></nav>
<form method="get" class="analytics-filter" action="#specialty-title"><input type="hidden" name="page" value="analytics"><input type="hidden" name="month" value="<?= h($analytics['month']) ?>"><?php if (isset($activity)): ?><input type="hidden" name="activity_date" value="<?= h($activity['date']) ?>"><?php endif ?><input type="hidden" name="sp" value="<?= h($sp['period']) ?>">
<?php if ($sp['period'] === 'day'): ?><label>Día<input type="date" name="sp_date" min="1000-01-01" max="9998-12-31" value="<?= h($sp['date']) ?>" required></label>
<?php elseif ($sp['period'] === 'month'): ?><label>Mes<input type="month" name="sp_date" min="1000-01" max="9998-12" value="<?= h(substr($sp['start'], 0, 7)) ?>" required></label>
<?php else: ?><label>Año<input type="number" name="sp_date" min="1000" max="9998" step="1" inputmode="numeric" value="<?= h(substr($sp['start'], 0, 4)) ?>" required></label><?php endif ?>
<button class="button primary">Ver</button></form></div></div>
<?php if (!$sp['total']): ?>
<div class="empty-state"><?= ui_icon('chart', 28) ?><h3>No hay atenciones registradas <?= h($sp['period'] === 'month' ? 'en ' . $spLabel : $spLabel) ?></h3><p>Elige otra fecha o carga las historias clínicas correspondientes.</p></div>
<?php else:
    $topNames = array_column($sp['top'], 'name');
    $topText = count($topNames) > 1 ? implode(', ', array_slice($topNames, 0, -1)) . ' y ' . end($topNames) : $topNames[0];
    // Las 5 especialidades con más atenciones llevan color; el resto se agrupa en «Otras» (gris).
    $parts = [];
    foreach (array_slice($sp['rows'], 0, 5) as $i => $row) $parts[] = ['label' => $row['name'], 'value' => $row['total'], 'color' => $colors[$i]];
    $rest = array_slice($sp['rows'], 5);
    if ($rest) $parts[] = ['label' => 'Otras (' . count($rest) . ')', 'value' => array_sum(array_column($rest, 'total')), 'color' => 'sc-other'];
?>
<div class="demand-top"><span class="demand-kicker"><?= ui_icon('flag', 16) ?><?= count($topNames) > 1 ? 'Empate en el primer lugar' : 'Más demandada' ?> · <?= h($spLabel) ?></span><strong><?= h($topText) ?></strong><span><?= $fmt($sp['top'][0]['total']) ?> <?= $sp['top'][0]['total'] === 1 ? 'atención' : 'atenciones' ?><?= count($topNames) > 1 ? ' cada una' : '' ?> · <?= $pct($sp['top'][0]['percentage']) ?> de <?= $fmt($sp['total']) ?></span></div>
<div class="donut-block"><div class="donut-wrap"><?= $donut($parts, 'Atenciones por especialidad, ' . $spLabel) ?><p class="donut-center"><strong><?= $fmt($sp['total']) ?></strong><span><?= $sp['total'] === 1 ? 'atención' : 'atenciones' ?></span></p></div>
<ol class="donut-legend"><?php foreach ($parts as $part): ?><li><span class="swatch <?= $part['color'] ?>"></span><span class="legend-name"><?= h($part['label']) ?></span><span class="legend-value"><?= $fmt($part['value']) ?></span><span class="legend-share"><?= $pct($part['value'] / $sp['total'] * 100) ?></span></li><?php endforeach ?></ol></div>
<details class="chart-table"><summary>Ver todas las especialidades (<?= count($sp['rows']) ?>)</summary>
<div class="table-wrap"><table class="analytics-table"><caption>Especialidades de mayor a menor cantidad de atenciones, <?= h($spLabel) ?>. Porcentajes redondeados a un decimal.</caption><thead><tr><th scope="col">Especialidad</th><th scope="col">Atenciones</th><th scope="col">Porcentaje</th></tr></thead><tbody>
<?php foreach ($sp['rows'] as $row): ?><tr><th scope="row"><?= h($row['name']) ?></th><td class="num"><?= $row['total'] ?></td><td class="num"><strong><?= number_format($row['percentage'],1,',','.') ?>%</strong></td></tr><?php endforeach ?>
</tbody></table></div></details>
<p class="chart-note">Cada atención cuenta una vez, aunque tenga varios anexos o versiones. Incluye historias retiradas.</p>
<?php endif ?>
</section>

<div class="analytics-split">

<?php if (isset($channels)): $channelTotal = $channels['whatsapp'] + $channels['web']; ?>
<section class="card analytics-card" aria-labelledby="channel-title">
<div class="chart-heading"><h2 id="channel-title">Solicitudes por canal</h2><p class="muted"><?= h(ucfirst($monthLabel)) ?> · por fecha de la solicitud</p></div>
<?php if (!$channelTotal): ?>
<div class="empty-state"><?= ui_icon('inbox', 28) ?><h3>No hubo solicitudes en <?= h($monthLabel) ?></h3><p>Las solicitudes aparecen aquí según el día en que se crearon.</p></div>
<?php else: $channelParts = [['label' => 'WhatsApp', 'value' => $channels['whatsapp'], 'color' => 'sc1'], ['label' => 'Solo web', 'value' => $channels['web'], 'color' => 'sc2']]; ?>
<div class="donut-block"><div class="donut-wrap"><?= $donut($channelParts, 'Solicitudes por canal en ' . $monthLabel) ?><p class="donut-center"><strong><?= $fmt($channelTotal) ?></strong><span>solicitudes</span></p></div>
<ul class="donut-legend"><?php foreach ($channelParts as $part): ?><li><span class="swatch <?= $part['color'] ?>"></span><span class="legend-name"><?= h($part['label']) ?></span><span class="legend-value"><?= $fmt($part['value']) ?></span><span class="legend-share"><?= $pct($part['value'] / $channelTotal * 100) ?></span></li><?php endforeach ?></ul></div>
<p class="chart-note">WhatsApp: la solicitud quedó vinculada a un número (bot o código). Solo web: el paciente no vinculó WhatsApp.</p>
<?php endif ?>
</section>
<?php endif ?>
</div>
<?php if (isset($activity)): require __DIR__ . '/activity-totals.php'; endif ?>
<div class="chart-tooltip" role="status" hidden><strong></strong><span></span></div>
