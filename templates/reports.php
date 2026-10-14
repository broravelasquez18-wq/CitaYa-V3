<?php require_once __DIR__ . '/icons.php'; ?>
<?php
// Reportes de error (SCRUM-48): casos abiertos y cerrados. El empleado ve los suyos; el administrador, todos.
$filterClosed = ($_GET['f'] ?? 'open') === 'closed';
$statusLabels = ['open' => 'Abierto', 'closed' => 'Resuelto', 'auto_resolved' => 'Resuelto por el bot'];
?>
<section class="card"><h2 class="visually-hidden">Reportes de error</h2><p class="muted">Abre un reporte para conversar con el paciente por el número del bot.</p>
<nav class="segmented" aria-label="Filtro de reportes"><a href="?page=reports" <?= !$filterClosed ? 'aria-current="page"' : '' ?>>Abiertos</a><a href="?page=reports&amp;f=closed" <?= $filterClosed ? 'aria-current="page"' : '' ?>>Resueltos</a></nav>
<?php if (!$reportList): ?><div class="empty-state"><?= ui_icon($filterClosed ? 'archive' : 'check-circle', 28) ?><p><?= $filterClosed ? 'Todavía no hay reportes resueltos.' : 'No hay reportes abiertos. Todo al día.' ?></p></div>
<?php else: ?><div class="report-list"><?php foreach ($reportList as $ticket): $unread = (int)$ticket['unread']; ?>
<a class="report-item <?= $unread ? 'unread' : '' ?>" href="?page=report&amp;id=<?= (int)$ticket['id'] ?>"><div><strong class="mono ref"><?= h($ticket['reference']) ?></strong> · <?= h($ticket['category_label']) ?><small><?= h($ticket['claimed_name'] ?? 'Paciente') ?><?= $ticket['request_reference'] ? ' · ' . h($ticket['request_reference']) : '' ?> · +<?= h($ticket['phone']) ?><?= ($_SESSION['admin_role'] ?? '') === 'admin' ? ' · ' . h($ticket['assigned_name'] ?? 'Sin asignar') : '' ?></small></div>
<div class="report-meta"><span class="pill <?= $ticket['status'] === 'open' ? 'live' : 'ok' ?>"><?= h($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span><?php if ($unread): ?><span class="tab-count" aria-label="<?= $unread ?> mensajes nuevos"><?= $unread ?></span><?php endif ?><small><?= h(date('d/m/Y h:i a', strtotime($ticket['updated_at']))) ?></small></div></a>
<?php endforeach ?></div><?php endif ?></section>
