<?php require_once __DIR__ . '/icons.php';
// Plantillas de mensajes: las que crea el administrador (respuestas y chat) y las del sistema; cada una se edita en su drawer.
$messageTemplates = new App\MessageTemplates($app); $sample = App\MessageTemplates::sample();
$failedCode = ui_reopen('save-template', 'reset-template') ? (string)($_POST['code'] ?? '') : '';
$customTemplates = $messageTemplates->custom();
// Si falló crear o editar, el drawer se reabre con lo que se escribió.
$failedCustom = ui_reopen('custom-template-save') ? (int)($_POST['template_id'] ?? 0) : -1;
$usageVariables = static fn(string $use) => implode(' ', array_map(fn($v) => '{' . $v . '}', App\MessageTemplates::CUSTOM_USES[$use][2]));
// Formulario de una plantilla creada: nueva ($template null) o existente.
$customForm = static function (?array $template) use ($failedCustom, $usageVariables): void {
    $id = $template ? (int)$template['id'] : 0;
    $dialogId = $id ? 'custom-template-' . $id : 'custom-template-new';
    $reopen = $failedCustom === $id;
    $value = static fn(string $field, string $saved) => $reopen ? (string)($_POST[$field] ?? '') : $saved;
    $use = $value('usage', $template['usage_code'] ?? 'approved'); ?>
<dialog <?= ui_dialog_attrs($dialogId, 'drawer', $reopen, 'drawer-wide template-card') ?>>
<?= ui_sheet_head($dialogId, $id ? 'Editar plantilla' : 'Crear plantilla', $id ? $template['name'] : 'Escribe un texto que se pueda reutilizar al responder.') ?>
<div class="sheet-body">
<form method="post" action="?page=templates" id="<?= $dialogId ?>-form" class="custom-template-form"><?= csrf() ?><input type="hidden" name="action" value="custom-template-save"><input type="hidden" name="template_id" value="<?= $id ?>">
<label>Nombre<input name="name" maxlength="80" required value="<?= h($value('name', $template['name'] ?? '')) ?>" placeholder="Ej.: Aprobado con anexos de laboratorio"></label>
<label>¿Dónde se usará?<select name="usage" required data-usage-select><?php foreach (App\MessageTemplates::CUSTOM_USES as $code => [$label, $help]): ?><option value="<?= $code ?>" data-variables="<?= h($usageVariables($code)) ?>" data-help="<?= h($help) ?>" <?= $use === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach ?></select></label>
<p class="muted usage-help" data-usage-help><?= h(App\MessageTemplates::CUSTOM_USES[$use][1] ?? '') ?> Variables: <code data-usage-variables><?= h($usageVariables(isset(App\MessageTemplates::CUSTOM_USES[$use]) ? $use : 'approved')) ?></code></p>
<label>Texto<textarea name="body" rows="10" minlength="10" maxlength="3000" required><?= h($value('body', $template['body'] ?? '')) ?></textarea></label>
</form>
<div class="sheet-foot"><?php if ($id): ?><form method="post" action="?page=templates" class="foot-start" data-confirm="Dejará de aparecer al responder. Los mensajes ya enviados no cambian." data-confirm-title="¿Eliminar «<?= h($template['name']) ?>»?" data-confirm-label="Eliminar plantilla" data-confirm-tone="danger"><?= csrf() ?><input type="hidden" name="action" value="custom-template-delete"><input type="hidden" name="template_id" value="<?= $id ?>"><button class="button secondary" type="submit">Eliminar</button></form><?php endif ?><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary" type="submit" form="<?= $dialogId ?>-form"><?= $id ? 'Guardar cambios' : 'Crear plantilla' ?></button></div>
</div>
</dialog>
<?php };
?>
<section class="card"><h2>Plantillas creadas</h2>
<p class="muted">Textos propios para reutilizar. Las de respuesta aparecen en el menú «Plantillas» al responder una solicitud; las del chat, como respuesta rápida en los reportes de error.</p>
<?php if (!$customTemplates): ?><div class="empty-state"><?= ui_icon('template', 28) ?><p>Todavía no has creado plantillas. Usa «Crear plantilla» para agregar la primera.</p></div>
<?php else: ?><ul class="item-list">
<?php foreach ($customTemplates as $template): [$useLabel] = App\MessageTemplates::CUSTOM_USES[$template['usage_code']] ?? ['Sin uso']; ?>
<li class="item-row"><div class="item-main"><strong><?= h($template['name']) ?></strong><small><?= h(mb_strimwidth(str_replace("\n", ' ', $template['body']), 0, 120, '…')) ?></small></div><span class="pill <?= $template['usage_code'] === 'approved' ? 'ok' : ($template['usage_code'] === 'not_registered' ? 'stop' : 'info') ?>"><?= h($useLabel) ?></span><a class="button secondary small" href="#custom-template-<?= (int)$template['id'] ?>" data-open="custom-template-<?= (int)$template['id'] ?>" aria-label="Editar plantilla «<?= h($template['name']) ?>»">Editar</a></li>
<?php endforeach ?></ul><?php endif ?>
</section>

<section class="card spaced"><h2>Plantillas del sistema</h2>
<p class="muted">Mensajes que el sistema envía solo en cada paso. Escribe las variables entre llaves, por ejemplo <code>{radicado}</code>; cada plantilla solo admite las que se indican. En WhatsApp, el texto entre asteriscos (<code>*así*</code>) se ve en negrita; en el correo los asteriscos se quitan.</p>
<ul class="item-list">
<?php foreach (App\MessageTemplates::DEFINITIONS as $code => [$title, $description, $variables]): $custom = $messageTemplates->isCustom($code); $removed = $messageTemplates->isRemoved($code); $removable = App\MessageTemplates::isRemovable($code); ?>
<li class="item-row<?= $removed ? ' is-removed' : '' ?>"><div class="item-main"><strong><?= h($title) ?></strong><small><?= h($description) ?></small></div>
<?php if ($removed): ?><span class="pill stop">Eliminada</span><form method="post" action="?page=templates" data-confirm="Vuelve con su texto predeterminado y se usa de nuevo en los próximos mensajes." data-confirm-title="¿Restaurar «<?= h($title) ?>»?" data-confirm-label="Restaurar plantilla"><?= csrf() ?><input type="hidden" name="action" value="reset-template"><input type="hidden" name="code" value="<?= h($code) ?>"><button class="button secondary small" type="submit">Restaurar</button></form>
<?php else: ?><?php if (!$removable): ?><span class="pill" title="El bot o los correos la usan en cada solicitud: se puede editar, pero no eliminar."><?= ui_icon('lock', 13) ?>Necesaria</span><?php endif ?><span class="pill <?= $custom ? 'info' : '' ?>"><?= $custom ? 'Personalizada' : 'Predeterminada' ?></span><a class="button secondary small" href="#template-<?= h($code) ?>" data-open="template-<?= h($code) ?>" aria-label="Editar plantilla «<?= h($title) ?>»">Editar</a><?php if ($removable): ?><form method="post" action="?page=templates" data-confirm="<?= str_starts_with($code, 'order_') ? 'El bot dejará de enviar esta indicación debajo de las órdenes de este tipo.' : 'Dejará de aparecer en «Plantillas» al responder una solicitud.' ?> Podrás recuperarla con «Restaurar»." data-confirm-title="¿Eliminar «<?= h($title) ?>»?" data-confirm-label="Eliminar plantilla" data-confirm-tone="danger"><?= csrf() ?><input type="hidden" name="action" value="remove-template"><input type="hidden" name="code" value="<?= h($code) ?>"><button class="button secondary small danger-text" type="submit" aria-label="Eliminar plantilla «<?= h($title) ?>»">Eliminar</button></form><?php endif ?><?php endif ?></li>
<?php endforeach ?>
</ul></section>
<?php $customForm(null); foreach ($customTemplates as $template) $customForm($template); ?>
<?php foreach (App\MessageTemplates::DEFINITIONS as $code => [$title, $description, $variables]): $body = $messageTemplates->body($code); $reopen = $failedCode === $code; ?>
<dialog <?= ui_dialog_attrs('template-' . h($code), 'drawer', $reopen, 'drawer-wide template-card') ?>>
<?= ui_sheet_head('template-' . h($code), $title, $description) ?>
<div class="sheet-body">
<p class="muted">Variables: <?= $variables ? implode(' ', array_map(fn($v) => '<code>{' . h($v) . '}</code>', $variables)) : 'ninguna' ?>.</p>
<form method="post" action="?page=templates" id="template-form-<?= h($code) ?>"><?= csrf() ?><input type="hidden" name="action" value="save-template"><input type="hidden" name="code" value="<?= h($code) ?>">
<label>Texto<textarea name="body" rows="12" maxlength="3000" required><?= $reopen && ($_POST['action'] ?? '') === 'save-template' ? h($_POST['body'] ?? '') : h($body) ?></textarea></label>
</form>
<details><summary>Vista previa con datos de ejemplo</summary><pre class="template-preview"><?= h($messageTemplates->render($code, $sample)) ?></pre></details>
<div class="sheet-foot"><?php if ($messageTemplates->isCustom($code)): ?><form method="post" action="?page=templates" class="foot-start" data-confirm="Se perderá el texto personalizado y se usará el predeterminado en los próximos mensajes." data-confirm-title="¿Restaurar «<?= h($title) ?>»?" data-confirm-label="Restaurar predeterminada" data-confirm-tone="danger"><?= csrf() ?><input type="hidden" name="action" value="reset-template"><input type="hidden" name="code" value="<?= h($code) ?>"><button class="button secondary" type="submit">Restaurar predeterminada</button></form><?php endif ?><a class="button secondary" href="#" data-close>Cancelar</a><button class="button primary" type="submit" form="template-form-<?= h($code) ?>">Guardar plantilla</button></div>
</div>
</dialog>
<?php endforeach ?>
