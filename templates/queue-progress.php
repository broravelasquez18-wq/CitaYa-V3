<?php require_once __DIR__ . '/icons.php'; ?>
<dialog class="queue-progress" id="queue-progress" data-queue-state="<?= h($request['status']) ?>" aria-labelledby="queue-title" aria-describedby="queue-message">
    <header class="queue-progress-header">
        <div class="call-mini"><span>Radicado</span><strong class="mono"><?= h($request['reference']) ?></strong></div>
        <button type="button" class="queue-progress-close" aria-label="Cerrar ventana de progreso"><?= ui_icon('x', 18) ?></button>
    </header>
    <div class="queue-progress-body">
        <h2 id="queue-title">Preparando tu envío…</h2><p class="queue-subtitle" data-queue-subtitle>Tu solicitud está en cola</p>
        <div class="queue-progress-ring" role="progressbar" aria-label="Procesamiento del envío" aria-valuetext="En cola">
            <svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><line class="queue-ring-track" x1="0" y1="3" x2="100" y2="3"/><line class="queue-ring-fill" x1="0" y1="3" x2="100" y2="3"/></svg>
        </div>
        <p id="queue-message" role="status" aria-live="polite">Esperando turno para preparar tu historia…</p>
        <small>Puedes cerrar esta ventana. El envío continuará.</small>
    </div>
</dialog>
