<?php require_once __DIR__ . '/icons.php';
// Plantillas de mensajes: lista de textos; cada uno se edita en su drawer (texto, vista previa y restauración).
$messageTemplates = new App\MessageTemplates($app); $sample = App\MessageTemplates::sample();
$failedCode = ui_reopen('save-template', 'reset-template') ? (string)($_POST['code'] ?? '') : '';
?>
<section class="card"><h2 class="visually-hidden">Plantillas de mensajes</h2>
<p class="muted">Escribe las variables entre llaves, por ejemplo <code>{radicado}</code>; cada plantilla solo admite las que se indican. En WhatsApp, el texto entre asteriscos (<code>*así*</code>) se ve en negrita; en el correo los asteriscos se quitan.</p>
<ul class="item-list">
<?php foreach (App\MessageTemplates::DEFINITIONS as $code => [$title, $description, $variables]): $custom = $messageTemplates->isCustom($code); ?>
<li class="item-row"><div class="item-main"><strong><?= h($title) ?></strong><small><?= h($description) ?></small></div><span class="pill <?= $custom ? 'info' : '' ?>"><?= $custom ? 'Personalizada' : 'Predeterminada' ?></span><a class="button secondary small" href="#template-<?= h($code) ?>" data-open="template-<?= h($code) ?>" aria-label="Editar plantilla «<?= h($title) ?>»">Editar</a></li>
<?php endforeach ?>
</ul></section>
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
