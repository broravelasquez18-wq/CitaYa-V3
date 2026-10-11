<?php
// Historial del caso: línea de tiempo de la solicitud (se abre en su propia ventana desde Solicitudes o el detalle).
if (!$caseHistory): ?><section class="card spaced"><h2>Solicitud no encontrada</h2><p class="muted">El radicado indicado no está disponible.</p></section>
<?php else:
$record = $caseHistory['request'];
$svg = static fn(string $path, int $size = 16): string => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
$icons = [
    'web' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'response' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'reopen' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'whatsapp' => '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-5.1A8.5 8.5 0 1 1 21 11.5Z"/>',
];
$tone = static fn(string $status): string => match ($status) {
    'ok', 'sent', 'simulated' => 'ok',
    'fail', 'failed', 'uncertain', 'cancelled', 'not_registered' => 'fail',
    'info', 'awaiting_selection' => 'info',
    default => 'pending',
};
$statusLabel = static fn(array $entry): string => $entry['badge'] ?? match ($entry['status']) {
    'pending' => 'Pendiente', 'ok' => 'Completado', 'info' => 'Por elegir', 'fail' => 'No Registra',
    default => $labels[$entry['status']] ?? $entry['status'],
};
$pillIcon = $svg('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>', 13);
$currentLabel = $record['awaiting'] ? 'Pendiente' : ($labels[$record['status']] ?? $record['status']);
$currentTone = $record['awaiting'] ? 'pending' : $tone($record['status']);
?>
<span hidden data-case-title>#<?= h($record['reference']) ?></span>
<div class="case-history">
<div class="history-summary">
<div><span class="case-label">Paciente</span><strong><?= h(mb_strtoupper((string)$record['claimed_name'])) ?></strong><?php if (empty($historyModal)): ?><small>Caso #<?= h($record['reference']) ?></small><?php endif ?></div>
<div><span class="case-label">Estado actual</span><span class="history-pill <?= $currentTone ?>"><?= $pillIcon ?><?= h($currentLabel) ?></span></div>
</div>
<ol class="history-timeline">
<?php foreach ($caseHistory['entries'] as $entry): $at = strtotime($entry['at']); ?>
<li class="history-entry">
<span class="history-dot <?= $entry['icon'] ?>"><?= $svg($icons[$entry['icon']] ?? $icons['web']) ?></span>
<div class="history-body">
<h3><?= h($entry['title']) ?></h3>
<span class="history-pill <?= $tone($entry['status']) ?>"><?= $pillIcon ?><?= h($statusLabel($entry)) ?></span>
<p class="history-text"><?= nl2br(h($entry['text'])) ?></p>
<div class="history-meta"><span><?= $svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 13) ?><?= h(date('d/m/Y h:i ', $at) . (date('A', $at) === 'AM' ? 'a. m.' : 'p. m.')) ?></span><span class="history-by"><?= $svg('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>', 13) ?>Por: <?= h($entry['by']) ?></span></div>
<?php if (!empty($entry['notified'])): ?><div class="history-meta"><span><?= $svg($icons['mail'], 13) ?>Notificado a: <?php if (filter_var($entry['notified'], FILTER_VALIDATE_EMAIL)): ?><a href="mailto:<?= h($entry['notified']) ?>"><?= h($entry['notified']) ?></a><?php else: ?><?= h($entry['notified']) ?><?php endif ?></span></div><?php endif ?>
</div>
</li>
<?php endforeach ?>
</ol>
<div class="history-actions"><?php if (empty($historyModal)): ?><a class="button secondary" href="?page=delivery&amp;id=<?= (int)$record['id'] ?>">Volver al detalle</a><?php else: ?><button type="button" class="button secondary" data-close-history>Cerrar</button><?php endif ?></div>
</div>
<?php endif ?>
