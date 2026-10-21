<?php
// Especialidad con más demanda, por día, mes o año (lo arma index.php; las pruebas pueden pasar solo el mes).
$sp = $specialty ?? ['period' => 'month', 'date' => $analytics['month'] . '-01', 'start' => $analytics['month'] . '-01', 'total' => $analytics['total'], 'rows' => $analytics['rows'],
    'top' => $analytics['rows'] ? array_values(array_filter($analytics['rows'], fn($r) => $r['total'] === $analytics['rows'][0]['total'])) : []];
$spStart = new DateTimeImmutable($sp['start']);
$spLabel = match ($sp['period']) {
    'day' => 'el ' . (int)$spStart->format('j') . ' de ' . $monthNames[$spStart->format('m')] . ' de ' . $spStart->format('Y'),
    'month' => $monthNames[$spStart->format('m')] . ' de ' . $spStart->format('Y'),
    default => 'el año ' . $spStart->format('Y'),
};
$spQuery = static fn(array $extra) => '?' . http_build_query(['page' => 'analytics', 'month' => $analytics['month']] + (isset($activity) ? ['activity_date' => $activity['date']] : []) + $extra);
$periods = ['day' => 'Día', 'month' => 'Mes', 'year' => 'Año'];

if ($sp['total']) {
    $topNames = array_column($sp['top'], 'name');
    $tie = count($topNames) > 1;
    $topText = $tie ? implode(', ', array_slice($topNames, 0, -1)) . ' y ' . end($topNames) : $topNames[0];
    $topTotal = $sp['top'][0]['total'];
    // Las 5 especialidades con más atenciones llevan color; el resto se agrupa en «Otras» (gris).
    $parts = [];
    foreach (array_slice($sp['rows'], 0, 5) as $i => $row) $parts[] = ['label' => $row['name'], 'value' => $row['total'], 'color' => $colors[$i]];
    $rest = array_slice($sp['rows'], 5);
    if ($rest) $parts[] = ['label' => 'Otras (' . count($rest) . ')', 'value' => array_sum(array_column($rest, 'total')), 'color' => 'sc-other'];
}
?>
<section class="card analytics-card demand-card" aria-labelledby="specialty-title">
<header class="analytics-heading">
<div><h2 id="specialty-title">¿Qué especialidad tuvo más demanda?</h2><p class="muted">Atenciones registradas en las historias clínicas cargadas, por la fecha de atención del PDF.</p></div>
<div class="demand-filter">
<nav class="segmented" aria-label="Período"><?php foreach ($periods as $key => $name): ?><a href="<?= h($spQuery(['sp' => $key, 'sp_date' => $sp['date']])) ?>#specialty-title" <?= $sp['period'] === $key ? 'aria-current="page"' : '' ?>><?= $name ?></a><?php endforeach ?></nav>
<form method="get" class="analytics-filter" action="#specialty-title"><?= $keepFilters(['month' => $analytics['month'], 'activity_date' => $activity['date'] ?? null, 'sp' => $sp['period']]) ?>
<?php if ($sp['period'] === 'day'): ?><label>Día<input type="date" name="sp_date" min="1000-01-01" max="9998-12-31" value="<?= h($sp['date']) ?>" required></label>
<?php elseif ($sp['period'] === 'month'): ?><label>Mes<input type="month" name="sp_date" min="1000-01" max="9998-12" value="<?= h(substr($sp['start'], 0, 7)) ?>" required></label>
<?php else: ?><label>Año<input type="number" name="sp_date" min="1000" max="9998" step="1" inputmode="numeric" value="<?= h(substr($sp['start'], 0, 4)) ?>" required></label><?php endif ?>
<button class="button primary">Ver</button></form>
</div>
</header>

<?php if (!$sp['total']): ?>
<div class="empty-state"><?= ui_icon('chart', 28) ?><h3>No hay atenciones registradas <?= h($sp['period'] === 'month' ? 'en ' . $spLabel : $spLabel) ?></h3><p>Elige otra fecha o carga las historias clínicas correspondientes.</p></div>
<?php else: ?>
<div class="demand-top"><span class="demand-kicker"><?= ui_icon('flag', 16) ?><?= $tie ? 'Empate en el primer lugar' : 'Más demandada' ?> · <?= h($spLabel) ?></span><strong><?= h($topText) ?></strong><span><?= $fmt($topTotal) ?> <?= $topTotal === 1 ? 'atención' : 'atenciones' ?><?= $tie ? ' cada una' : '' ?> · <?= $pct($sp['top'][0]['percentage']) ?> de <?= $fmt($sp['total']) ?></span></div>

<div class="donut-block">
<div class="donut-wrap"><?= $donut($parts, 'Atenciones por especialidad, ' . $spLabel) ?><p class="donut-center"><strong><?= $fmt($sp['total']) ?></strong><span><?= $sp['total'] === 1 ? 'atención' : 'atenciones' ?></span></p></div>
<?= $legend($parts, $sp['total'], 'ol') ?>
</div>

<details class="chart-table">
<summary>Ver todas las especialidades (<?= count($sp['rows']) ?>)</summary>
<div class="table-wrap"><table class="analytics-table">
<caption>Especialidades de mayor a menor cantidad de atenciones, <?= h($spLabel) ?>. Porcentajes redondeados a un decimal.</caption>
<thead><tr><th scope="col">Especialidad</th><th scope="col">Atenciones</th><th scope="col">Porcentaje</th></tr></thead>
<tbody>
<?php foreach ($sp['rows'] as $row): ?>
<tr><th scope="row"><?= h($row['name']) ?></th><td class="num"><?= $row['total'] ?></td><td class="num"><strong><?= number_format($row['percentage'], 1, ',', '.') ?>%</strong></td></tr>
<?php endforeach ?>
</tbody>
</table></div>
</details>
<p class="chart-note">Cada atención cuenta una vez, aunque tenga varios anexos o versiones. Incluye historias retiradas.</p>
<?php endif ?>
</section>
