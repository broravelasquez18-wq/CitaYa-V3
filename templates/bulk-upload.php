<?php $bulkLimits=App\BulkImport::limits(); ?>
<form method="post" enctype="multipart/form-data" data-bulk-upload data-max-files="<?= $bulkLimits['files'] ?>" data-max-file-bytes="<?= $bulkLimits['file_bytes'] ?>" data-max-total-bytes="<?= $bulkLimits['total_bytes'] ?>"><?= csrf() ?><input type="hidden" name="action" value="bulk-upload"><input type="hidden" name="expected_files" value="0">
<label class="upload-zone"><span class="upload-zone-title"><?= ui_icon('files', 22) ?>Seleccionar PDF</span><input type="file" name="bulk_pdfs[]" accept="application/pdf,.pdf" multiple required><small>Hasta <?= $bulkLimits['files'] ?> PDF por lote · <?= h(round($bulkLimits['file_bytes']/1024**2,1)) ?> MB por archivo · <?= h(round($bulkLimits['total_bytes']/1024**2,1)) ?> MB en total. Cada atención admite hasta 15 PDF.</small></label>
<p class="muted" data-bulk-selection role="status">No has seleccionado archivos.</p>
<label class="check"><input type="checkbox" name="complete" value="1" required><span>Incluí todos los PDF de cada atención que estoy cargando.</span></label>
<p class="muted">Si algún PDF no se puede leer, se guardan los demás y el resultado te indica cuál falló y por qué. Repetir una carga ya registrada no duplica los archivos.</p>
<p class="muted" data-bulk-progress role="status" hidden>Leyendo y organizando los PDF. Espera a que termine la carga.</p><div class="sheet-foot"><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary" type="submit"><?= ui_icon('upload', 18) ?>Cargar PDF</button></div>
</form>
