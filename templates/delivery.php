<?php require_once __DIR__ . '/icons.php'; if (empty($deliveryModal)): ?><a class="back-link" href="?page=deliveries<?= ($_GET['from'] ?? '') === 'history' ? '&amp;f=approved' : '' ?>&amp;p=<?= max(1,(int)($_GET['p'] ?? 1)) ?>"><?= ui_icon('arrow-left', 16) ?>Volver a los resultados</a><?php endif ?>
<?php if (!$deliveryDetail): ?><section class="card spaced"><h2>Solicitud no encontrada</h2><p class="muted">El radicado indicado no está disponible.</p></section>
<?php else: $record=$deliveryDetail['request']; ?>
<?php
$icon = static fn(string $path): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
$copyIcon = $icon('<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>');
$created = strtotime((string)$record['created_at']);
$createdText = date('d/m/Y, h:i ', $created) . (date('A', $created) === 'AM' ? 'a. m.' : 'p. m.');
?>
<?php
$caseTone = !empty($record['case_pending']) || !empty($record['awaiting']) ? 'live' : ui_tone($record['status']);
$caseState = !empty($record['case_pending']) || !empty($record['awaiting']) ? 'Pendiente de gestión' : ($labels[$record['status']] ?? $record['status']);
$resolutionLabels = ['assigned' => 'Historia asignada', 'approved' => 'Aprobado', 'not_registered' => 'No Registra'];
$canRespond = !empty($record['awaiting']) && $record['direct_search'] && in_array($record['status'], App\Application::PENDING_STATUSES, true);
// Solo se muestra «verificada» si la solicitud tiene constancia de validación (datos coincidentes o correo verificado).
$verified = !empty($record['matched_at']) || !empty($record['verified_at']);
$chevron = $icon('<path d="m6 9 6 6 6-6"/>');
?>
<span hidden data-case-title>Detalle de la solicitud</span>
<div class="case-layout">
<aside class="case-info" aria-label="Datos de la solicitud">
<section class="case-panel case-ref-panel"><div class="case-panel-top"><span class="case-kicker">Radicado oficial</span><button type="button" class="copy-chip" data-copy="<?= h($record['reference']) ?>" aria-label="Copiar radicado <?= h($record['reference']) ?>"><?= $copyIcon ?>Copiar</button></div>
<strong class="case-ref mono"><?= h($record['reference']) ?></strong>
<span class="pill <?= $caseTone ?>"><?= ui_icon(['live' => 'clock', 'ok' => 'check-circle', 'stop' => 'alert', 'wait' => 'search'][$caseTone], 14) ?><?= h($caseState) ?></span></section>
<section class="case-panel"><div class="case-panel-top"><h4 class="case-panel-title"><?= $icon('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>') ?>Paciente</h4><?= $verified ? '<span class="case-badge ok">Identificación verificada</span>' : '<span class="case-badge wait">Por verificar</span>' ?></div>
<span class="case-kicker muted">Nombre completo</span>
<strong class="case-name"><?= h(mb_strtoupper((string)$record['claimed_name'])) ?></strong>
<dl class="case-tiles">
<div><dt>Documento</dt><dd><?php if ($record['document_number']): ?><span><?= h($record['document_type'] . ' ' . $record['document_number']) ?></span><button type="button" class="copy-button" data-copy="<?= h($record['document_number']) ?>" aria-label="Copiar documento" title="Copiar"><?= $copyIcon ?></button><?php else: ?><em>No conservado</em><?php endif ?></dd></div>
<div><dt>Fecha de atención</dt><dd><?= $record['approximate_date'] ? h(date('d/m/Y', strtotime($record['approximate_date']))) : '<em>No la recuerda</em>' ?></dd></div>
<div><dt>Especialidad</dt><dd><?= $record['specialty'] ? h($record['specialty']) : '<em>No indicada</em>' ?></dd></div>
<div><dt>En los PDF</dt><dd><?= $record['indexed_name'] ? h($record['indexed_name']) : '<span class="case-chip warn">' . ui_icon('alert', 13) . 'Sin coincidencia</span>' ?></dd></div>
</dl></section>
<section class="case-panel"><h4 class="case-panel-title"><?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>') ?>Contacto registrado</h4>
<dl class="case-contact">
<div><dt>Correo electrónico</dt><dd><?php if ($record['contact_email']): ?><a href="mailto:<?= h($record['contact_email']) ?>"><?= h($record['contact_email']) ?><?= $icon('<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>') ?></a><?php else: ?><em>No indicado</em><?php endif ?></dd></div>
<div><dt>WhatsApp</dt><dd><?php if ($record['whatsapp_phone']): ?><span class="mono">+<?= h($record['whatsapp_phone']) ?></span><button type="button" class="copy-button" data-copy="<?= h($record['whatsapp_phone']) ?>" aria-label="Copiar número de WhatsApp" title="Copiar"><?= $copyIcon ?></button><?php else: ?><em>No indicado</em><?php endif ?></dd></div>
</dl></section>
<section class="case-panel"><h4 class="case-panel-title kicker"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>') ?>Requerimiento</h4>
<span class="case-chip brand">Historia clínica</span>
<p class="case-note">Esta solicitud fue radicada a través de la Web el día <strong><?= h($createdText) ?></strong>.</p>
<?php if ($record['attended_at']): ?><p class="case-note">Atención seleccionada: <strong><?= h(date('d/m/Y H:i', strtotime($record['attended_at']))) ?></strong></p><?php endif ?>
<?php if ($record['resolution']): ?><p class="case-note">Respuesta: <strong><?= h($resolutionLabels[$record['resolution']] ?? $record['resolution']) ?></strong> · <?= h($record['resolved_by_name'] ?? 'Administrador') ?> · <?= h($record['resolved_at']) ?></p><?php endif ?>
<?php if ($record['case_pending']): ?><p class="case-note pending"><strong>Caso reabierto</strong> el <?= h($record['case_reopened_at']) ?>. Los envíos anteriores se conservan.</p><?php endif ?>
</section>
</aside>
<div class="case-main">
<?php $historyRow = '<a class="case-history-row" data-history-modal aria-haspopup="dialog" href="?page=case-history&amp;id=' . (int)$record['id'] . '"><span class="case-icon-box">' . ui_icon('clock', 18) . '</span><span><strong>Historial del caso</strong><small>Radicación, respuestas y envíos por correo y WhatsApp.</small></span>' . $chevron . '</a>'; ?>
<?php if ($canRespond):
$messageTemplates = new App\MessageTemplates($app);
$templateValues = ['radicado' => $record['reference'], 'nombre' => $record['claimed_name'], 'enlace' => $app->whatsapp()->portalUrl(),
    'documento' => trim(($record['document_type'] ?? '') . ' ' . ($record['document_number'] ?? '')), 'fecha' => $record['approximate_date'] ? date('d/m/Y', strtotime($record['approximate_date'])) : 'fecha que indicaste',
    'especialidad' => $record['specialty'] ?? 'la especialidad indicada'];
// Plantillas del menú: código => [título, estado que selecciona].
$responseTemplates = ['response_approved' => ['Historia aprobada', 'approved'], 'nr_document' => ['No registra: número de documento', 'not_registered'], 'nr_name' => ['No registra: nombre', 'not_registered'],
    'nr_date' => ['No registra: fecha de atención', 'not_registered'], 'nr_specialty' => ['No registra: especialidad', 'not_registered'], 'not_registered' => ['No registra: general', 'not_registered']];
// Los motivos que el administrador eliminó no se ofrecen.
$responseTemplates = array_filter($responseTemplates, fn($code) => !$messageTemplates->isRemoved($code), ARRAY_FILTER_USE_KEY); ?>
<form method="post" action="?page=deliveries" enctype="multipart/form-data" class="case-respond" data-case-respond novalidate><?= csrf() ?><input type="hidden" name="action" value="respond-case"><input type="hidden" name="request_id" value="<?= (int)$record['id'] ?>">
<div class="case-body">
<div class="case-respond-head"><label class="case-section-title" for="case-comment-<?= (int)$record['id'] ?>"><span class="case-icon-box brand"><?= $icon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>') ?></span>Comentario de gestión / respuesta</label>
<div class="case-tools">
<details class="case-menu" data-decision-menu><summary><?= $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') ?><span data-decision-label>Seleccionar estado</span><?= $chevron ?></summary>
<div class="case-menu-list" role="radiogroup" aria-label="Estado de la respuesta">
<label class="case-option"><input type="radio" name="decision" value="approved" required><span class="case-option-icon approved"><?= $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') ?></span><span data-option-label>Aprobado</span></label>
<label class="case-option"><input type="radio" name="decision" value="not_registered"><span class="case-option-icon rejected"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><span data-option-label>No Registra</span></label>
</div></details>
<details class="case-menu"><summary><?= $icon('<path d="M4 6h16M4 12h16M4 18h10"/>') ?>Plantillas<?= $chevron ?></summary>
<div class="case-menu-list">
<?php foreach ($responseTemplates as $templateCode => [$templateTitle, $templateDecision]): ?><button type="button" class="case-option" data-template="<?= h($templateCode) ?>" data-decision="<?= $templateDecision ?>"<?= in_array($templateCode, ['response_approved', 'not_registered'], true) ? ' data-default' : '' ?> data-template-text="<?= h($messageTemplates->renderPlain($templateCode, $templateValues)) ?>"><span class="case-option-icon <?= $templateDecision === 'approved' ? 'approved' : 'rejected' ?>"><?= $templateDecision === 'approved' ? $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') : $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><?= h($templateTitle) ?></button><?php endforeach ?><?php foreach ($messageTemplates->custom() as $customTemplate): if (!in_array($customTemplate['usage_code'], ['approved', 'not_registered'], true)) continue; $customDecision = $customTemplate['usage_code']; ?><button type="button" class="case-option" data-template="custom-<?= (int)$customTemplate['id'] ?>" data-decision="<?= $customDecision ?>" data-template-text="<?= h(App\MessageTemplates::renderCustom($customTemplate, $templateValues, true)) ?>"><span class="case-option-icon <?= $customDecision === 'approved' ? 'approved' : 'rejected' ?>"><?= $customDecision === 'approved' ? $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') : $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><?= h($customTemplate['name']) ?></button><?php endforeach ?>
</div></details>
</div></div>
<div class="case-editor">
<textarea id="case-comment-<?= (int)$record['id'] ?>" name="comment" required minlength="10" maxlength="3000" placeholder="Detalle la respuesta al paciente o el motivo de la decisión…" data-case-comment></textarea>
<div class="case-editor-foot"><span><?= ui_icon('info', 14) ?>Al elegir el estado se propone su plantilla; puedes ajustarla.</span><span class="mono" data-char-count aria-live="polite">0 caracteres</span></div>
</div>
<p class="case-section-title small"><?= $icon('<path d="m21 11-8.5 8.5a5 5 0 0 1-7-7l9-9a3.3 3.3 0 0 1 4.7 4.7l-9 9a1.7 1.7 0 0 1-2.4-2.4l8.3-8.3"/>') ?>Adjuntar documentos despachados</p>
<label class="case-drop" data-drop data-max="<?= (int)$app->config['max_response_bytes'] ?>"><input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-drop-input>
<span class="case-drop-icon"><?= $icon('<path d="M12 13v8M8 17l4-4 4 4"/><path d="M20 16.6A5 5 0 0 0 18 7h-1.3A8 8 0 1 0 4 15.3"/>') ?></span><span class="case-drop-text"><u>Arrastre o haga clic</u> para subir archivos PDF / Imágenes</span><small>Soportados: PDF, JPG, PNG (máx. <?= intdiv((int)$app->config['max_response_bytes'], 1048576) ?> MB en total, límite del correo)</small></label>
<ul class="case-files" data-drop-list></ul>
<p class="case-hint"><?= ui_icon('check-circle', 15) ?><span>La respuesta sale al correo <strong><?= h($record['contact_email'] ?? '') ?></strong><?= $record['whatsapp_phone'] ? ' y al WhatsApp <strong>+' . h($record['whatsapp_phone']) . '</strong>' : '' ?>. Para «Aprobado» recuerde adjuntar los soportes.</span></p>
<?= $historyRow ?>
</div>
<div class="case-actions"><button type="button" class="button secondary" data-close-case>Cerrar</button><button class="button primary" type="submit"><?= $icon('<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>') ?>Guardar y notificar</button></div>
</form>
<?php else: $done = empty($record['awaiting']); ?><div class="case-body case-body-done"><div class="case-done<?= $done ? '' : ' live' ?>"><span class="case-done-icon"><?= ui_icon($done ? 'check-circle' : 'clock', 26) ?></span><h4><?= $done ? 'Esta solicitud ya fue gestionada' : 'Pendiente de gestión' ?></h4><p><?= $done ? 'La respuesta y cada envío por correo y WhatsApp están en el historial del caso.' : 'Este caso no se responde desde este formulario. Revisa su seguimiento en el historial.' ?></p></div><?= $historyRow ?></div>
<?php endif ?>
</div></div>
<?php endif ?>
