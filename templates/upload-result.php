<?php if (isset($_SESSION['single_upload_result']) || !empty($_SESSION['bulk_report'])):
$autoOpen = !empty($_SESSION['upload_result_pending']);
unset($_SESSION['upload_result_pending']);
?>
<div data-upload-result data-auto-open="<?= $autoOpen ? '1' : '0' ?>">
<button type="button" class="button secondary spaced" data-open-upload-result hidden>Ver resultado de la última carga</button>
<div data-upload-result-report>
<?php if (isset($_SESSION['single_upload_result'])): ?>
<section class="card spaced" aria-label="Resultado de carga individual"><h2>Resultado de la última carga</h2>
<?php if (is_array($_SESSION['single_upload_result'])): $loadSummary = $_SESSION['single_upload_result']; ?>
<p class="muted"><?= (int)$loadSummary['received'] ?> PDF recibidos · <span class="pill"><?= $loadSummary['reused'] ? 'Ya estaba registrada' : 'Carga completada' ?></span></p>
<p>Esto es lo que el sistema leyó en los PDF y lo que hizo:</p>
<?php require __DIR__ . '/upload-summary.php'; ?>
<?php else: ?><p class="muted"><?= (int) $_SESSION['single_upload_result'] ?> PDF recibidos.</p><span class="pill">Carga completada</span><p>Historia completa registrada y disponible para solicitudes.</p><?php endif ?>
</section>
<?php else: require __DIR__ . '/bulk-report.php'; endif ?>
</div>
<dialog class="upload-result-modal" aria-labelledby="upload-result-title">
    <header class="upload-result-header"><h2 id="upload-result-title">Resultado de la carga de PDF</h2><button type="button" class="button secondary" data-close-upload-result>Cerrar <span aria-hidden="true">×</span></button></header>
    <div class="upload-result-content" data-upload-result-content></div>
</dialog>
</div>
<script src="assets/upload-result.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/upload-result.js') ?>" defer></script>
<?php endif ?>
