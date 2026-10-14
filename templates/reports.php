<?php
// Reportes de error (SCRUM-48): casos abiertos y cerrados. El empleado ve los suyos; el administrador, todos.
$filterClosed = ($_GET['f'] ?? 'open') === 'closed';
$statusLabels = ['open' => 'Abierto', 'closed' => 'Resuelto', 'auto_resolved' => 'Resuelto por el bot'];
?>
<section class="card"><h2>Reportes de error</h2><p class="muted">Errores en la historia clínica que los pacientes reportaron por WhatsApp y el bot no pudo resolver solo. Abre uno para conversar con el paciente por el número del bot.</p>
<nav class="tabs delivery-filter" aria-label="Filtro de reportes"><a class="<?= !$filterClosed ? 'selected' : '' ?>" href="?page=reports">Abiertos</a><a class="<?= $filterClosed ? 'selected' : '' ?>" href="?page=reports&amp;f=closed">Resueltos</a></nav>
<?php if (!$reportList): ?><div class="empty-state"><?= $filterClosed ? 'Todavía no hay reportes resueltos.' : 'No hay reportes abiertos. ¡Todo al día!' ?></div>
<?php else: ?><div class="report-list"><?php foreach ($reportList as $ticket): $unread = (int)$ticket['unread']; ?>
<a class="report-item <?= $unread ? 'unread' : '' ?>" href="?page=report&amp;id=<?= (int)$ticket['id'] ?>"><div><strong><?= h($ticket['reference']) ?></strong> · <?= h($ticket['category_label']) ?><small><?= h($ticket['claimed_name'] ?? 'Paciente') ?><?= $ticket['request_reference'] ? ' · ' . h($ticket['request_reference']) : '' ?> · +<?= h($ticket['phone']) ?><?= ($_SESSION['admin_role'] ?? '') === 'admin' ? ' · ' . h($ticket['assigned_name'] ?? 'Sin asignar') : '' ?></small></div>
<div class="report-meta"><span class="pill <?= $ticket['status'] === 'open' ? 'sla-warn' : 'sla-ok' ?>"><?= h($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span><?php if ($unread): ?><span class="tab-count" aria-label="<?= $unread ?> mensajes nuevos"><?= $unread ?></span><?php endif ?><small><?= h(date('d/m/Y h:i a', strtotime($ticket['updated_at']))) ?></small></div></a>
<?php endforeach ?></div><?php endif ?></section>
