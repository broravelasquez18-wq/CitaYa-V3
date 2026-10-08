<?php $messageTemplates = new App\MessageTemplates($app); $sample = App\MessageTemplates::sample(); ?>
<section class="card spaced"><h2>Plantillas de mensajes</h2>
<p class="muted">Textos que recibe el paciente por WhatsApp y correo. Escribe las variables entre llaves, por ejemplo <code>{radicado}</code>; cada plantilla solo admite las que se indican. En WhatsApp, el texto entre asteriscos (<code>*así*</code>) se ve en negrita; en el correo los asteriscos se quitan.</p></section>
<?php foreach (App\MessageTemplates::DEFINITIONS as $code => [$title, $description, $variables]): $body = $messageTemplates->body($code); ?>
<section class="card spaced template-card" aria-labelledby="template-<?= h($code) ?>">
<div class="mail-top"><h3 id="template-<?= h($code) ?>"><?= h($title) ?></h3><span class="pill"><?= $messageTemplates->isCustom($code) ? 'Personalizada' : 'Predeterminada' ?></span></div>
<p class="muted"><?= h($description) ?> Variables: <?= $variables ? implode(' ', array_map(fn($v) => '<code>{' . h($v) . '}</code>', $variables)) : 'ninguna' ?>.</p>
<form method="post" action="?page=templates"><?= csrf() ?><input type="hidden" name="action" value="save-template"><input type="hidden" name="code" value="<?= h($code) ?>">
<label>Texto<textarea name="body" rows="7" maxlength="3000" required><?= h($_POST['code'] ?? '') === $code && $error ? h($_POST['body'] ?? '') : h($body) ?></textarea></label>
<button class="button primary" type="submit">Guardar plantilla</button></form>
<details class="spaced"><summary>Vista previa con datos de ejemplo</summary><pre class="template-preview"><?= h($messageTemplates->render($code, $sample)) ?></pre></details>
<?php if ($messageTemplates->isCustom($code)): ?><form method="post" action="?page=templates" class="spaced"><?= csrf() ?><input type="hidden" name="action" value="reset-template"><input type="hidden" name="code" value="<?= h($code) ?>"><label class="check"><input type="checkbox" required>Reemplazar por el texto predeterminado</label><button class="button secondary" type="submit">Restaurar predeterminada</button></form><?php endif ?>
</section>
<?php endforeach ?>
