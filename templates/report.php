<?php
// Chat de un reporte de error (SCRUM-48). Con $reportFragment solo se devuelven los mensajes (actualización automática).
$renderMessages = static function (array $ticket): void {
    foreach ($ticket['messages'] as $message):
        $who = $message['direction'] === 'in' ? 'Paciente' : ($message['direction'] === 'system' ? 'Bot' : ($message['author'] ?? 'Personal')); ?>
<div class="chat-message <?= h($message['direction']) ?>"><div class="chat-bubble"><?= nl2br(h($message['body'])) ?></div><small><?= h($who) ?> · <?= h(date('d/m h:i a', strtotime($message['created_at']))) ?></small></div>
<?php endforeach;
};
if (!empty($reportFragment)) { if ($reportDetail) $renderMessages($reportDetail); return; }
if (!$reportDetail): ?><section class="card"><h2>Reporte no encontrado</h2><p class="muted">No existe o no está asignado a tu cuenta.</p><a class="back-link" href="?page=reports">← Volver a reportes</a></section>
<?php return; endif;
$ticket = $reportDetail;
$open = $ticket['status'] === 'open';
?>
<a class="back-link" href="?page=reports">← Volver a reportes</a>
<div class="report-layout spaced">
<aside class="card report-info"><span class="eyebrow">REPORTE <?= h($ticket['reference']) ?></span><h2 class="spaced"><?= h($ticket['category_label']) ?></h2>
<dl class="delivery-facts single"><div><dt>Paciente</dt><dd><?= h($ticket['claimed_name'] ?? '—') ?></dd></div><div><dt>WhatsApp</dt><dd>+<?= h($ticket['phone']) ?></dd></div><?php if ($ticket['request_reference']): ?><div><dt>Historia</dt><dd><a href="?page=case-history&amp;id=<?= (int)$ticket['request_id'] ?>" data-history-modal><?= h($ticket['request_reference']) ?></a></dd></div><?php endif ?><div><dt>Asignado a</dt><dd><?= h($ticket['assigned_name'] ?? 'Sin asignar') ?></dd></div><div><dt>Creado</dt><dd><?= h(date('d/m/Y h:i a', strtotime($ticket['created_at']))) ?></dd></div><div><dt>Estado</dt><dd><?= $open ? 'Abierto' : 'Resuelto' ?></dd></div></dl>
<?php if ($open): ?><form method="post" action="?page=report&amp;id=<?= (int)$ticket['id'] ?>" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="report-close"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><button class="button secondary full">Marcar como resuelto</button><small>El paciente recibe un mensaje de cierre y vuelve al menú del bot.</small></form><?php endif ?>
</aside>
<section class="card report-chat"><h2>Conversación</h2><p class="muted">Los mensajes salen por el WhatsApp del bot. Lo que escriba el paciente aparece aquí mientras el reporte esté abierto.</p>
<div class="chat-thread" data-chat-thread data-chat-url="?page=report&amp;id=<?= (int)$ticket['id'] ?>&amp;fragment=1" aria-live="polite"><?php $renderMessages($ticket) ?></div>
<?php if ($open): ?><form method="post" action="?page=report&amp;id=<?= (int)$ticket['id'] ?>" class="chat-form"><?= csrf() ?><input type="hidden" name="action" value="report-send"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label class="visually-hidden" for="chat-body">Mensaje para el paciente</label><textarea id="chat-body" name="body" required maxlength="2000" rows="3" placeholder="Escribe tu respuesta al paciente…" data-chat-input></textarea><button class="button primary">Enviar</button></form>
<?php else: ?><div class="notice"><p>Este reporte está resuelto. Si el paciente vuelve a escribir, el bot le mostrará el menú.</p></div><?php endif ?>
</section></div>
<dialog id="history-modal" class="delivery-modal history-modal" aria-labelledby="history-modal-title"><div class="delivery-modal-header"><div class="case-head"><div><h2 id="history-modal-title">Historial del caso</h2><small data-history-reference></small></div></div><button class="case-close" type="button" data-close-history aria-label="Cerrar historial del caso">×</button></div><div class="delivery-modal-content" data-history-content aria-live="polite"></div></dialog>
