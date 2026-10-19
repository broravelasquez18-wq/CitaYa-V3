<?php require_once __DIR__ . '/icons.php'; if (empty($deliveryModal)): ?><a class="back-link" href="?page=<?= ($_GET['from'] ?? '') === 'history' ? 'history' : 'deliveries' ?>&amp;q=<?= rawurlencode(mb_substr((string)($_GET['q'] ?? ''),0,160)) ?>&amp;p=<?= max(1,(int)($_GET['p'] ?? 1)) ?>"><?= ui_icon('arrow-left', 16) ?>Volver a los resultados</a><?php endif ?>
<?php if (!$deliveryDetail): ?><section class="card spaced"><h2>Solicitud no encontrada</h2><p class="muted">El radicado indicado no está disponible.</p></section>
<?php else: $record=$deliveryDetail['request']; ?>
<?php
$icon = static fn(string $path): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
$copyIcon = $icon('<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>');
$created = strtotime((string)$record['created_at']);
$createdText = date('d/m/Y, h:i ', $created) . (date('A', $created) === 'AM' ? 'a. m.' : 'p. m.');
?>
<span hidden data-case-title>Detalle de la solicitud</span>
<div class="case-layout">
<aside class="case-info" aria-label="Información de la solicitud">
<?php $caseTone = !empty($record['case_pending']) || !empty($record['awaiting']) ? 'live' : ui_tone($record['status']); ?>
<section class="call-plate compact <?= $caseTone ?>" aria-label="Radicado y estado"><div class="call-number"><span class="call-label">Radicado</span><strong class="call-ref"><?= h($record['reference']) ?></strong></div><div class="call-state"><?= ui_icon(['live' => 'clock', 'ok' => 'check-circle', 'stop' => 'alert', 'wait' => 'search'][$caseTone], 20) ?><h2><?= $caseTone === 'live' && (!empty($record['case_pending']) || !empty($record['awaiting'])) ? 'Pendiente de gestión' : h($labels[$record['status']] ?? $record['status']) ?></h2></div></section>
<div class="case-band patient"><?= $icon('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>') ?>Paciente</div>
<div class="case-card">
<div class="case-field"><span class="case-label">Nombre completo</span><span class="case-value strong"><span class="case-dot patient"><?= $icon('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>') ?></span><?= h(mb_strtoupper((string)$record['claimed_name'])) ?></span></div>
<div class="case-pair">
<div class="case-field"><span class="case-label">Documento</span><span class="case-value"><span class="case-dot document"><?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h4M14 14h3"/>') ?></span><?php if ($record['document_number']): ?><?= h($record['document_type'] . ' ' . $record['document_number']) ?><button type="button" class="copy-button" data-copy="<?= h($record['document_number']) ?>" aria-label="Copiar documento" title="Copiar"><?= $copyIcon ?></button><?php else: ?>No conservado<?php endif ?></span></div>
<div class="case-field"><span class="case-label">Fecha de atención</span><span class="case-value date"><?= $record['approximate_date'] ? h(date('d/m/Y', strtotime($record['approximate_date']))) : 'No la recuerda' ?></span></div>
</div>
<div class="case-pair">
<div class="case-field"><span class="case-label">Especialidad</span><span class="case-value"><?= h($record['specialty'] ?? 'No indicada') ?></span></div>
<div class="case-field"><span class="case-label">En los PDF</span><span class="case-value"><?= h($record['indexed_name'] ?? 'Sin coincidencia') ?></span></div>
</div>
</div>
<div class="case-band contact"><?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>') ?>Contacto</div>
<div class="case-card">
<div class="case-field"><span class="case-label">Correo electrónico</span><span class="case-value"><span class="case-dot mail"><?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>') ?></span><?php if ($record['contact_email']): ?><a href="mailto:<?= h($record['contact_email']) ?>"><?= h($record['contact_email']) ?></a><?php else: ?>—<?php endif ?></span></div>
<div class="case-field"><span class="case-label">WhatsApp</span><span class="case-value phone"><span class="case-dot phone"><?= $icon('<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>') ?></span><?php if ($record['whatsapp_phone']): ?>+<?= h($record['whatsapp_phone']) ?><button type="button" class="copy-button" data-copy="<?= h($record['whatsapp_phone']) ?>" aria-label="Copiar número de WhatsApp" title="Copiar"><?= $copyIcon ?></button><?php else: ?><span class="muted">No indicado</span><?php endif ?></span></div>
</div>
<div class="case-band request"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>') ?>Requerimiento</div>
<div class="case-card">
<div class="case-tags"><span class="case-tag">Historia clínica</span></div>
<p class="case-note">Esta solicitud fue radicada a través de la Web el día <?= h($createdText) ?></p>
<?php if ($record['attended_at']): ?><p class="case-note">Atención seleccionada: <strong><?= h(date('d/m/Y H:i', strtotime($record['attended_at']))) ?></strong></p><?php endif ?>
<?php if ($record['resolution']): ?><p class="case-note">Respuesta: <strong><?= h(['assigned' => 'Historia asignada', 'approved' => 'Aprobado', 'not_registered' => 'No Registra'][$record['resolution']] ?? $record['resolution']) ?></strong> · <?= h($record['resolved_by_name'] ?? 'Administrador') ?> · <?= h($record['resolved_at']) ?></p><?php endif ?>
<?php if ($record['case_pending']): ?><p class="case-note pending"><strong>Pendiente de gestión.</strong> Caso reabierto el <?= h($record['case_reopened_at']) ?>. Los envíos anteriores se conservan.</p><?php endif ?>
</div>
</aside>
<div class="case-main">
<?php if (!empty($record['awaiting']) && $record['direct_search'] && in_array($record['status'], App\Application::PENDING_STATUSES, true)):
$messageTemplates = new App\MessageTemplates($app);
$templateValues = ['radicado' => $record['reference'], 'nombre' => $record['claimed_name'], 'enlace' => $app->whatsapp()->portalUrl(),
    'documento' => trim(($record['document_type'] ?? '') . ' ' . ($record['document_number'] ?? '')), 'fecha' => $record['approximate_date'] ? date('d/m/Y', strtotime($record['approximate_date'])) : 'fecha que indicaste',
    'especialidad' => $record['specialty'] ?? 'la especialidad indicada'];
// Plantillas del menú: código => [título, estado que selecciona].
$responseTemplates = ['response_approved' => ['Historia aprobada', 'approved'], 'nr_document' => ['No registra: número de documento', 'not_registered'], 'nr_name' => ['No registra: nombre', 'not_registered'],
    'nr_date' => ['No registra: fecha de atención', 'not_registered'], 'nr_specialty' => ['No registra: especialidad', 'not_registered'], 'not_registered' => ['No registra: general', 'not_registered']];
$chevron = $icon('<path d="m6 9 6 6 6-6"/>'); ?>
<form method="post" action="?page=deliveries" enctype="multipart/form-data" class="case-respond" data-case-respond><?= csrf() ?><input type="hidden" name="action" value="respond-case"><input type="hidden" name="request_id" value="<?= (int)$record['id'] ?>">
<div class="case-respond-head"><h3 class="case-section-title"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>') ?>Comentario de gestión / respuesta</h3>
<div class="case-tools">
<details class="case-menu" data-decision-menu><summary><?= $icon('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>') ?><span data-decision-label>Seleccionar estado</span><?= $chevron ?></summary>
<div class="case-menu-list" role="radiogroup" aria-label="Estado de la respuesta">
<label class="case-option"><input type="radio" name="decision" value="approved" required><span class="case-option-icon approved"><?= $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') ?></span><span data-option-label>Aprobado</span></label>
<label class="case-option"><input type="radio" name="decision" value="not_registered"><span class="case-option-icon rejected"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><span data-option-label>No Registra</span></label>
</div></details>
<details class="case-menu"><summary><?= $icon('<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>') ?>Plantillas<?= $chevron ?></summary>
<div class="case-menu-list">
<?php foreach ($responseTemplates as $templateCode => [$templateTitle, $templateDecision]): ?><button type="button" class="case-option" data-template="<?= h($templateCode) ?>" data-decision="<?= $templateDecision ?>"<?= in_array($templateCode, ['response_approved', 'not_registered'], true) ? ' data-default' : '' ?> data-template-text="<?= h($messageTemplates->renderPlain($templateCode, $templateValues)) ?>"><span class="case-option-icon <?= $templateDecision === 'approved' ? 'approved' : 'rejected' ?>"><?= $templateDecision === 'approved' ? $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') : $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><?= h($templateTitle) ?></button><?php endforeach ?><?php foreach ($messageTemplates->custom() as $customTemplate): if (!in_array($customTemplate['usage_code'], ['approved', 'not_registered'], true)) continue; $customDecision = $customTemplate['usage_code']; ?><button type="button" class="case-option" data-template="custom-<?= (int)$customTemplate['id'] ?>" data-decision="<?= $customDecision ?>" data-template-text="<?= h(App\MessageTemplates::renderCustom($customTemplate, $templateValues, true)) ?>"><span class="case-option-icon <?= $customDecision === 'approved' ? 'approved' : 'rejected' ?>"><?= $customDecision === 'approved' ? $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') : $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span><?= h($customTemplate['name']) ?></button><?php endforeach ?>
</div></details>
</div></div>
<label class="visually-hidden" for="case-comment-<?= (int)$record['id'] ?>">Comentario para el paciente</label>
<textarea id="case-comment-<?= (int)$record['id'] ?>" name="comment" required minlength="10" maxlength="3000" placeholder="Detalle la respuesta al paciente o el motivo de la decisión…" data-case-comment></textarea>
<h3 class="case-section-title attach"><?= $icon('<path d="m21 11-8.5 8.5a5 5 0 0 1-7-7l9-9a3.3 3.3 0 0 1 4.7 4.7l-9 9a1.7 1.7 0 0 1-2.4-2.4l8.3-8.3"/>') ?>Adjuntar documentos despachados</h3>
<label class="case-drop" data-drop data-max="<?= (int)$app->config['max_response_bytes'] ?>"><input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-drop-input>
<?= $icon('<path d="M12 13v8M8 17l4-4 4 4"/><path d="M20 16.6A5 5 0 0 0 18 7h-1.3A8 8 0 1 0 4 15.3"/>') ?><strong>Arrastre o haga clic para subir archivos PDF / Imágenes</strong><small>Soportados: PDF, JPG, PNG (máx. <?= intdiv((int)$app->config['max_response_bytes'], 1048576) ?> MB en total, límite del correo)</small></label>
<ul class="case-files" data-drop-list></ul>
<p class="case-hint">La respuesta sale al correo <?= h($record['contact_email'] ?? '') ?><?= $record['whatsapp_phone'] ? ' y al WhatsApp +' . h($record['whatsapp_phone']) : '' ?>. Para «Aprobado» adjunta los documentos.</p>
<div class="case-actions"><button type="button" class="button secondary" data-close-case>Cerrar</button><button class="button primary" type="submit"><?= $icon('<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>') ?>Guardar y notificar</button></div>
</form>
<?php endif ?>
<?php if (empty($record['awaiting'])): ?><div class="notice spaced"><?= ui_icon('check-circle') ?><p><strong>Esta solicitud ya fue gestionada.</strong> Consulta en el historial la respuesta y los envíos.</p></div><?php endif ?>
<div class="case-history-link"><a class="history-button" data-history-modal aria-haspopup="dialog" href="?page=case-history&amp;id=<?= (int)$record['id'] ?>" aria-label="Ver historial del caso <?= h($record['reference']) ?>" title="Historial del caso"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg></a><div><strong>Historial del caso</strong><small>Radicación, respuestas y envíos por correo y WhatsApp.</small></div></div>
</div></div>
<?php endif ?>
