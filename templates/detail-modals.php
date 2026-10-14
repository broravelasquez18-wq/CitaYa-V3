<?php require_once __DIR__ . '/icons.php';
// Modales de detalle que el JS llena desde el servidor: la solicitud y el historial del caso (este puede abrirse encima).
$withDelivery ??= true; ?>
<dialog id="history-modal" class="modal modal-md" aria-labelledby="history-modal-title">
<header class="sheet-head"><div><h2 id="history-modal-title">Historial del caso</h2><p data-history-reference></p></div><button type="button" class="sheet-close" data-close aria-label="Cerrar historial del caso"><?= ui_icon('x', 20) ?></button></header>
<div class="sheet-body" data-remote-content aria-live="polite"></div>
</dialog>
<?php if ($withDelivery): ?>
<dialog id="delivery-modal" class="modal modal-wide" aria-labelledby="delivery-modal-title">
<header class="sheet-head"><div><h2 id="delivery-modal-title">Detalle de la solicitud</h2><p>Datos del paciente, contacto y gestión del caso.</p></div><button type="button" class="sheet-close" data-close aria-label="Cerrar detalle de la solicitud"><?= ui_icon('x', 20) ?></button></header>
<div class="sheet-body flush" data-remote-content aria-live="polite"></div>
</dialog>
<?php endif ?>
