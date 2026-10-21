<?php
// Solicitudes del mes por canal: vinculadas a WhatsApp o solo por la web.
$channelTotal = $channels['whatsapp'] + $channels['web'];
$channelParts = [['label' => 'WhatsApp', 'value' => $channels['whatsapp'], 'color' => 'sc1'], ['label' => 'Solo web', 'value' => $channels['web'], 'color' => 'sc2']];
?>
<section class="card analytics-card" aria-labelledby="channel-title">
<header class="chart-heading"><h2 id="channel-title">Solicitudes por canal</h2><p class="muted"><?= h(ucfirst($monthLabel)) ?> · por fecha de la solicitud</p></header>
<?php if (!$channelTotal): ?>
<div class="empty-state"><?= ui_icon('inbox', 28) ?><h3>No hubo solicitudes en <?= h($monthLabel) ?></h3><p>Las solicitudes aparecen aquí según el día en que se crearon.</p></div>
<?php else: ?>
<div class="donut-block">
<div class="donut-wrap"><?= $donut($channelParts, 'Solicitudes por canal en ' . $monthLabel) ?><p class="donut-center"><strong><?= $fmt($channelTotal) ?></strong><span>solicitudes</span></p></div>
<?= $legend($channelParts, $channelTotal, 'ul') ?>
</div>
<p class="chart-note">WhatsApp: la solicitud quedó vinculada a un número (bot o código). Solo web: el paciente no vinculó WhatsApp.</p>
<?php endif ?>
</section>
