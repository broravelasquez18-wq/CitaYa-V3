<?php require_once __DIR__ . '/icons.php';
$uploadLimits=App\BulkImport::limits();
$uploadMode=(($_POST['action'] ?? '')==='bulk-upload' || (empty($_POST['action']) && ($_SESSION['upload_mode'] ?? '')==='bulk')) ? 'bulk' : 'single';
?>
<dialog <?= ui_dialog_attrs('upload-drawer', 'drawer', ui_reopen('upload', 'bulk-upload'), 'drawer-wide') ?>>
<?= ui_sheet_head('upload-drawer', 'Cargar historias clínicas', 'Sube los PDF de CEDIM tal como salen del sistema. Solo PDF con texto.') ?>
<div class="sheet-body upload-center" data-upload-center data-upload-mode="<?= $uploadMode ?>">
<ol class="upload-flow" aria-label="Qué hace el sistema al cargar">
<li><strong>Lee el PDF</strong><span>Extrae el texto de cada página. Un PDF escaneado sin texto no se puede leer.</span></li>
<li><strong>Identifica la atención</strong><span>Paciente, documento, ingreso, fecha y especialidad.</span></li>
<li><strong>Verifica</strong><span>Que todos los archivos sean del mismo paciente e ingreso. Si no, no guarda nada.</span></li>
<li><strong>Detecta órdenes</strong><span>Fórmula, laboratorio, imágenes y otras, para orientar al paciente.</span></li>
<li><strong>Publica y entrega</strong><span>Queda disponible. Si el paciente ya la había pedido, se le envía en ese momento.</span></li>
</ol>
<div class="upload-options" role="tablist" aria-label="Tipo de carga" data-upload-options hidden>
<button type="button" id="upload-tab-single" class="upload-option" role="tab" aria-controls="upload-panel-single" aria-selected="true" data-upload-choice="single">
<span class="upload-option-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8Z"/><path d="M14 3v5h5M8 12h8M8 16h6"/></svg></span>
<span class="upload-option-copy"><strong>Una atención</strong><span>Historia completa de un paciente, con sus anexos.</span></span><span class="upload-choice-mark" aria-hidden="true"></span>
</button>
<button type="button" id="upload-tab-bulk" class="upload-option" role="tab" aria-controls="upload-panel-bulk" aria-selected="false" tabindex="-1" data-upload-choice="bulk">
<span class="upload-option-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 6h9l4 4v11H8ZM17 6v4h4M4 17H3V2h10l2 2M11 14h7M11 17h5"/></svg></span>
<span class="upload-option-copy"><strong>Varias atenciones</strong><span>Carga masiva de uno o varios pacientes.</span></span><span class="upload-choice-mark" aria-hidden="true"></span>
</button>
</div>
<div id="upload-panel-single" class="upload-panel" data-upload-panel="single">
<h3>Historia de una atención</h3><p class="muted upload-panel-description">Selecciona todos los PDF de <strong>una sola atención</strong>: la consulta, las órdenes, las fórmulas y los anexos. No necesitas escribir los datos del paciente: el sistema los toma del PDF y te muestra al final lo que leyó.</p>
<?php require __DIR__ . '/single-upload.php'; ?>
</div>
<div id="upload-panel-bulk" class="upload-panel" data-upload-panel="bulk">
<h3>Carga masiva de PDF</h3><p class="muted upload-panel-description">Selecciona los PDF de varias atenciones, de uno o varios pacientes, mezclados. El sistema los agrupa por paciente e ingreso, guarda cada atención por separado y al final te muestra qué guardó, qué ya existía y qué debes revisar.</p>
<?php require __DIR__ . '/bulk-upload.php'; ?>
</div>
</div>
</dialog>
<?php require __DIR__ . '/upload-result.php'; ?>
<?php require __DIR__ . '/upload-progress.php'; ?>
<script src="assets/upload-progress.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/upload-progress.js') ?>" defer></script>
