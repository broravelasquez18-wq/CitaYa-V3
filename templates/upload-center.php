<?php require_once __DIR__ . '/icons.php';
$uploadLimits=App\BulkImport::limits();
?>
<dialog <?= ui_dialog_attrs('upload-drawer', 'drawer', ui_reopen('upload', 'bulk-upload'), 'drawer-wide') ?>>
<?= ui_sheet_head('upload-drawer', 'Cargar historias clínicas', 'Sube uno o varios PDF, de una o de varias atenciones: el sistema los organiza solo.') ?>
<div class="sheet-body upload-center" data-upload-center>
<ol class="upload-flow" aria-label="Qué hace el sistema al cargar">
<li><strong>Lee el PDF</strong><span>Extrae el texto de cada página; las primeras páginas escaneadas se leen con OCR.</span></li>
<li><strong>Identifica la atención</strong><span>Paciente, documento, ingreso, fecha y especialidad, de las primeras páginas.</span></li>
<li><strong>Agrupa y verifica</strong><span>Junta los PDF por paciente e ingreso. Si una página es de otro paciente o ingreso, ese PDF no se carga.</span></li>
<li><strong>Detecta órdenes</strong><span>Fórmula, laboratorio, imágenes y otras, para orientar al paciente.</span></li>
<li><strong>Publica y entrega</strong><span>Queda disponible. Si el paciente ya la había pedido, se le envía en ese momento.</span></li>
</ol>
<div class="upload-panel">
<p class="muted upload-panel-description">Selecciona los PDF que tengas: la historia de una atención con sus anexos, o los de varias atenciones de uno o varios pacientes, mezclados. No necesitas separarlos ni escribir los datos del paciente. Al final verás qué guardó, qué ya existía y qué falta revisar; si a un PDF le falta un dato, podrás escribirlo ahí.</p>
<?php require __DIR__ . '/bulk-upload.php'; ?>
</div>
</div>
</dialog>
<?php require __DIR__ . '/upload-result.php'; ?>
<?php require __DIR__ . '/upload-progress.php'; ?>
<script src="assets/upload-progress.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/upload-progress.js') ?>" defer></script>
