<?php require_once __DIR__ . '/icons.php'; ?>
<dialog class="queue-progress upload-progress" id="upload-progress" aria-labelledby="upload-progress-title" aria-describedby="upload-progress-message">
    <header class="queue-progress-header">
        <h2 id="upload-progress-title">Cargando PDF…</h2>
        <button type="button" class="queue-progress-close" aria-label="Cerrar mensaje de carga" hidden><?= ui_icon('x', 18) ?></button>
    </header>
    <div class="queue-progress-body">
        <p class="queue-subtitle" data-upload-subtitle>Subiendo archivos al servidor…</p>
        <span class="upload-percent mono" data-upload-percent aria-hidden="true">0%</span>
        <div class="queue-progress-ring" role="progressbar" aria-label="Subida de archivos PDF">
            <svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><line class="queue-ring-track" x1="0" y1="3" x2="100" y2="3"/><line class="queue-ring-fill" x1="0" y1="3" x2="100" y2="3" pathLength="100"/></svg>
        </div>
        <p id="upload-progress-message" role="status" aria-live="polite">Subiendo archivos al servidor…</p>
        <small data-upload-note>Mantén esta página abierta hasta que termine.</small>
    </div>
</dialog>
