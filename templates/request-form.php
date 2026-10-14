<?php require_once __DIR__ . '/icons.php';
// Formulario de solicitud del paciente: una sola tarjeta con tres secciones numeradas y revisión en modal antes de radicar.
// Sin JavaScript el botón envía directamente con la validación nativa. Los nombres de campo no cambian.
$old = static fn(string $field, string $fallback = ''): string => (string)($_POST[$field] ?? $fallback);
$documentTypes = ['CC' => 'Cédula de ciudadanía', 'TI' => 'Tarjeta de identidad', 'CE' => 'Cédula de extranjería',
    'RC' => 'Registro civil', 'PA' => 'Pasaporte', 'PPT' => 'Permiso por protección temporal'];
$selectedType = isset($documentTypes[$old('document_type')]) ? $old('document_type') : 'CC';
$dateUnknown = $old('date_unknown') === '1';
$linkedPhone = $whatsappLink ? $app->whatsapp()->linkPhone($whatsappLink) : null;
?>
<form method="post" action="?page=home" class="request-form" data-request-form><?= csrf() ?><input type="hidden" name="action" value="request">
<?php if ($whatsappLink): ?><input type="hidden" name="wa" value="<?= h($whatsappToken) ?>"><div class="notice"><?= ui_icon('chat') ?><p><strong>Solicitud iniciada desde WhatsApp<?= $linkedPhone ? ' (' . h(App\WhatsApp::maskPhone($linkedPhone)) . ')' : '' ?>.</strong><br>Si encontramos tu historia la enviaremos al correo que indiques y también a este chat. Si no está cargada, te enviaremos por WhatsApp el radicado de tu solicitud.</p></div><?php endif ?>

<section class="form-section" data-section="1" aria-labelledby="section-1">
<h2 class="section-title" id="section-1">1. Datos del paciente</h2>
<div class="grid identity-grid">
<div class="field" data-field="document_type"><label for="f-doctype">Tipo de documento</label>
<div class="input-icon"><?= ui_icon('ticket', 18) ?><select id="f-doctype" name="document_type" required><?php foreach ($documentTypes as $value => $label): ?><option value="<?= $value ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach ?></select></div></div>
<div class="field" data-field="document_number"><label for="f-document">Número de documento</label>
<div class="input-icon"><?= ui_icon('file', 18) ?><input id="f-document" class="mono-input" name="document_number" inputmode="numeric" autocomplete="off" maxlength="24" placeholder="Ej.: 1023456789" value="<?= h($old('document_number')) ?>" required aria-describedby="f-document-help f-document-error"></div>
<small class="field-help" id="f-document-help" data-document-help>Sin puntos ni espacios.</small>
<p class="field-error" id="f-document-error" hidden></p></div>
</div>
<div class="field" data-field="full_name"><label for="f-name">Nombre completo del paciente</label>
<div class="input-icon"><?= ui_icon('user', 18) ?><input id="f-name" name="full_name" autocomplete="name" autocapitalize="words" maxlength="160" placeholder="Nombres y apellidos completos" value="<?= h($old('full_name')) ?>" required aria-describedby="f-name-help f-name-error"></div>
<small class="field-help" id="f-name-help">Tal como aparece en el documento: todos los nombres y los dos apellidos.</small>
<p class="field-error" id="f-name-error" hidden></p></div>
</section>

<section class="form-section" data-section="2" aria-labelledby="section-2">
<h2 class="section-title" id="section-2">2. Atención médica</h2>
<div class="grid">
<div class="field" data-field="approximate_date"><label for="f-date">Fecha aproximada de la atención</label>
<div class="input-icon"><?= ui_icon('calendar', 18) ?><input id="f-date" type="date" name="approximate_date" max="<?= date('Y-m-d') ?>" value="<?= h($old('approximate_date')) ?>" <?= $dateUnknown ? 'disabled' : 'required' ?> aria-describedby="date-help f-date-error"></div>
<p class="field-error" id="f-date-error" hidden></p></div>
<div class="field" data-field="specialty_id"><label for="f-specialty">Especialidad</label>
<div class="input-icon"><?= ui_icon('list', 18) ?><select id="f-specialty" name="specialty_id" required aria-describedby="f-specialty-error"><option value="" disabled <?= $old('specialty_id') === '' ? 'selected' : '' ?>>Selecciona una opción</option><option value="0" <?= $old('specialty_id') === '0' ? 'selected' : '' ?>>No recuerdo la especialidad</option><?php foreach ($specialties as $specialty): ?><option value="<?= $specialty['id'] ?>" <?= $old('specialty_id') === (string)$specialty['id'] ? 'selected' : '' ?>><?= h($specialty['name']) ?></option><?php endforeach ?></select></div>
<p class="field-error" id="f-specialty-error" hidden></p></div>
</div>
<label class="toggle-card"><input type="checkbox" name="date_unknown" value="1" <?= $dateUnknown ? 'checked' : '' ?>><span><strong>No recuerdo la fecha</strong><small id="date-help" data-date-help><?= $dateUnknown ? 'Te mostraremos la consulta más reciente disponible para que elijas si deseas recibirla.' : 'Buscaremos la consulta más reciente del paciente.' ?></small></span></label>
</section>

<section class="form-section" data-section="3" aria-labelledby="section-3">
<h2 class="section-title" id="section-3">3. Datos de contacto</h2>
<div class="grid">
<div class="field" data-field="email"><label for="f-email">Correo electrónico</label>
<div class="input-icon"><?= ui_icon('mail', 18) ?><input id="f-email" type="email" name="email" maxlength="190" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="ejemplo@correo.com" value="<?= h($old('email')) ?>" required aria-describedby="f-email-help f-email-error"></div>
<small class="field-help" id="f-email-help">Aquí se enviará la historia con sus anexos.</small>
<p class="field-suggest" data-email-suggest hidden></p>
<p class="field-error" id="f-email-error" hidden></p></div>
<?php if (!$whatsappLink): ?><div class="field" data-field="whatsapp"><label for="f-whatsapp">Celular con WhatsApp <span class="optional">(opcional)</span></label>
<div class="input-icon"><?= ui_icon('phone', 18) ?><input id="f-whatsapp" type="tel" name="whatsapp" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="Ej.: 300 123 4567" value="<?= h($old('whatsapp')) ?>" aria-describedby="whatsapp-help f-whatsapp-error"></div>
<small class="field-help" id="whatsapp-help">Para avisos y para recibirla también por WhatsApp.</small>
<p class="field-error" id="f-whatsapp-error" hidden></p></div><?php endif ?>
</div>
</section>

<div class="form-actions">
<button type="reset" class="button outline">Cancelar</button>
<button class="button cta" type="submit" data-review-submit><?= ui_icon('arrow-right', 20) ?>Radicar solicitud de historia</button>
</div>
<?php if ($app->config['mail_transport'] === 'local'): ?><p class="delivery-note"><?= ui_icon('info', 18) ?><span>Modo de prueba: el correo se prepara en la bandeja local, no sale a Internet.</span></p><?php endif ?>
<aside class="consent-note"><h3>Autorización de tratamiento de datos personales</h3><p>Al enviar este formulario autorizas el tratamiento de tus datos personales para atender esta solicitud, de acuerdo con la Política de Tratamiento de Datos Personales y la Ley 1581 de 2012.</p></aside>

<dialog id="review-modal" class="modal" aria-labelledby="review-modal-title">
<?= ui_sheet_head('review-modal', 'Revisa tus datos', 'Si algo no coincide con el documento, corrígelo antes de radicar.') ?>
<div class="sheet-body">
<dl class="review-list">
<div><dt>Paciente</dt><dd><strong data-review="full_name"></strong><span data-review="document"></span></dd><button type="button" class="text-button" data-review-edit="1">Cambiar</button></div>
<div><dt>Atención</dt><dd><strong data-review="date"></strong><span data-review="specialty"></span></dd><button type="button" class="text-button" data-review-edit="2">Cambiar</button></div>
<div><dt>Contacto</dt><dd><strong data-review="email"></strong><span data-review="whatsapp"></span></dd><button type="button" class="text-button" data-review-edit="3">Cambiar</button></div>
</dl>
<div class="sheet-foot"><button type="button" class="button secondary" data-close>Corregir</button><button type="button" class="button primary" data-review-confirm><?= ui_icon('send', 18) ?>Sí, radicar solicitud</button></div>
</div>
</dialog>
</form>
