<?php if (empty($deliveryModal)): ?><a class="back-link" href="?page=<?= ($_GET['from'] ?? '') === 'history' ? 'history' : 'deliveries' ?>&amp;q=<?= rawurlencode(mb_substr((string)($_GET['q'] ?? ''),0,160)) ?>&amp;p=<?= max(1,(int)($_GET['p'] ?? 1)) ?>">← Volver a los resultados</a><?php endif ?>
<?php if (!$deliveryDetail): ?><section class="card spaced"><h2>Solicitud no encontrada</h2><p class="muted">El radicado indicado no está disponible.</p></section>
<?php else: $record=$deliveryDetail['request']; ?>
<?php
$icon = static fn(string $path): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
$copyIcon = $icon('<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>');
$created = strtotime((string)$record['created_at']);
$createdText = date('d/m/Y, h:i ', $created) . (date('A', $created) === 'AM' ? 'a. m.' : 'p. m.');
?>
<span hidden data-case-title>Caso #<?= h($record['reference']) ?></span>
<div class="case-layout">
<aside class="case-info" aria-label="Información de la solicitud">
<h3 class="case-info-title"><?= $icon('<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>') ?>Información de la solicitud</h3>
<?php if (empty($deliveryModal)): ?><p class="case-reference">Caso #<?= h($record['reference']) ?></p><?php endif ?>
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
<div class="case-tags"><span class="case-tag">Historia clínica</span><span class="pill"><?= h($labels[$record['status']] ?? $record['status']) ?></span></div>
<p class="case-note">Esta solicitud fue radicada a través de la Web el día <?= h($createdText) ?></p>
<?php if ($record['attended_at']): ?><p class="case-note">Atención seleccionada: <strong><?= h(date('d/m/Y H:i', strtotime($record['attended_at']))) ?></strong></p><?php endif ?>
<?php if ($record['resolution']): ?><p class="case-note">Respuesta: <strong><?= h(['assigned' => 'Historia asignada', 'approved' => 'Aprobado', 'not_registered' => 'No Registra'][$record['resolution']] ?? $record['resolution']) ?></strong> · <?= h($record['resolved_by_name'] ?? 'Administrador') ?> · <?= h($record['resolved_at']) ?></p><?php endif ?>
<?php if ($record['case_pending']): ?><p class="case-note pending"><strong>Pendiente de gestión.</strong> Caso reabierto el <?= h($record['case_reopened_at']) ?>. Los envíos anteriores se conservan.</p><?php endif ?>
</div>
</aside>
<div class="case-main">
<?php if (!empty($record['awaiting']) && $record['direct_search'] && in_array($record['status'], App\Application::PENDING_STATUSES, true)): $candidates = $deliveryDetail['candidates'] ?? []; $hasDocument = !empty($record['claimed_document_number']);
$messageTemplates = new App\MessageTemplates($app);
$templateValues = ['radicado' => $record['reference'], 'nombre' => $record['claimed_name'], 'enlace' => $app->whatsapp()->portalUrl()];
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
<button type="button" class="case-option" data-template="approved" data-template-text="<?= h($messageTemplates->renderPlain('response_approved', $templateValues)) ?>"><span class="case-option-icon approved"><?= $icon('<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>') ?></span>Historia aprobada</button>
<button type="button" class="case-option" data-template="not_registered" data-template-text="<?= h($messageTemplates->renderPlain('not_registered', $templateValues)) ?>"><span class="case-option-icon rejected"><?= $icon('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="m10 12 4 4m0-4-4 4"/>') ?></span>No registra</button>
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
<?php if ($hasDocument): ?><details class="case-repo"><summary>Historias del repositorio con este documento (<?= count($candidates) ?>)</summary>
<?php if (!$hasDocument): ?><div class="notice"><p>Esta solicitud antigua no conservó el número de documento, así que no se puede emparejar con una historia. Responde «No registra» para que el paciente haga una nueva solicitud con su documento.</p></div>
<?php else: ?><p class="muted">Solo se puede enviar una historia del mismo documento (<?= h($record['claimed_document_type'] . ' ' . $record['claimed_document_number']) ?>) cuyo nombre coincida con «<?= h($record['claimed_name']) ?>». El envío sale por correo<?= $record['whatsapp_phone'] ? ' y por WhatsApp' : '' ?>.</p>
<?php if (!$candidates): ?><div class="empty-state">No hay historias cargadas con este número de documento. Carga el PDF abajo o responde «No registra».</div><?php endif ?><?php endif ?>
<?php foreach ($candidates as $candidate): ?>
<article class="mail-item candidate"><div class="mail-top"><h3><?= h($candidate['specialty']) ?> · <?= h(date('d/m/Y H:i', strtotime($candidate['attended_at']))) ?></h3><span class="pill <?= $candidate['name_matches'] ? 'sla-ok' : 'sla-late' ?>"><?= $candidate['name_matches'] ? 'Nombre coincide' : 'Nombre distinto' ?></span></div>
<p class="muted"><?= h($candidate['location']) ?> · Ingreso <?= h($candidate['admission']) ?> · <?= (int)$candidate['file_count'] ?> PDF · Nombre en el PDF: <strong><?= h($candidate['patient_name']) ?></strong></p>
<?php if ($candidate['name_matches']): ?><form method="post" action="?page=deliveries" class="respond-form"><?= csrf() ?><input type="hidden" name="action" value="assign-encounter"><input type="hidden" name="request_id" value="<?= (int)$record['id'] ?>"><input type="hidden" name="encounter_id" value="<?= (int)$candidate['id'] ?>"><label class="check"><input type="checkbox" required>Confirmo que es la atención que pidió el paciente</label><button class="button primary" type="submit">Enviar esta historia</button></form>
<?php else: ?><p class="muted">El nombre no coincide con el de la solicitud: no se puede enviar. Si corresponde, responde «No registra» para que el paciente verifique sus datos.</p><?php endif ?>
</article><?php endforeach ?>
<?php if ($hasDocument): ?><details class="spaced"><summary>Cargar el PDF de esta solicitud</summary>
<form method="post" action="?page=deliveries" enctype="multipart/form-data" class="spaced respond-form"><?= csrf() ?><input type="hidden" name="action" value="request-upload"><input type="hidden" name="request_id" value="<?= (int)$record['id'] ?>"><label>PDF de la atención (uno o varios del mismo ingreso)<input type="file" name="pdfs[]" accept="application/pdf" multiple required></label><label class="check"><input type="checkbox" name="complete" value="1" required>Los archivos componen la historia completa de la atención</label><button class="button secondary" type="submit">Cargar y enviar si coincide</button><small>Si el documento y el nombre del PDF coinciden con la solicitud, se envía automáticamente. Si no, queda guardado en el repositorio sin enviarse.</small></form></details><?php endif ?>
</details><?php endif ?>
<?php endif ?>
<?php if (!empty($record['response_note'])): ?><section class="card spaced case-response-note"><span class="eyebrow">RESPUESTA ENVIADA</span><h3 class="spaced"><?= $record['resolution'] === 'approved' ? 'Aprobado' : 'No Registra' ?></h3><p class="case-note-text"><?= nl2br(h($record['response_note'])) ?></p><small><?= h($record['resolved_by_name'] ?? 'Administrador') ?> · <?= h($record['resolved_at']) ?></small></section><?php endif ?>
<section class="card spaced"><h2>Historial de envíos y reenvíos</h2><p class="muted">«Correo enviado» indica que el servidor aceptó el mensaje; no confirma su recepción en la bandeja de entrada ni su lectura.</p>
<?php if (!$deliveryDetail['deliveries']): ?><div class="empty-state">Esta solicitud todavía no ha generado un envío de historia clínica.</div><?php endif ?>
<?php foreach ($deliveryDetail['deliveries'] as $index=>$delivery): ?>
<article class="mail-item"><div class="mail-top"><h3><?= ($delivery['kind'] ?? 'history') !== 'history' ? 'Respuesta de la persona encargada' : ($index===count($deliveryDetail['deliveries'])-1 ? 'Envío original' : 'Reenvío ' . (count($deliveryDetail['deliveries'])-$index-1)) ?> · #<?= (int)$delivery['id'] ?></h3><span class="pill"><?= h($labels[$delivery['status']] ?? $delivery['status']) ?></span></div>
<dl class="delivery-facts"><div><dt>Destinatario de este envío</dt><dd><?= h($delivery['recipient']) ?></dd></div><div><dt>Intentos de procesamiento</dt><dd><?= (int)$delivery['attempts'] ?></dd></div><div><dt>Encolado</dt><dd><?= h($delivery['created_at']) ?></dd></div><div><dt><?= $delivery['status']==='simulated' ? 'Simulación preparada' : 'Aceptado por el servidor' ?></dt><dd><?= h($delivery['sent_at'] ?? 'Sin confirmación') ?></dd></div></dl>
<?php if ($delivery['last_error']): ?><div class="alert error"><strong>Motivo registrado:</strong> <?= h($delivery['last_error']) ?></div><?php endif ?>
<p class="muted"><strong>Asunto:</strong> Respuesta a tu solicitud <?= h($record['reference']) ?></p><h3>Archivos del envío</h3>
<?php if (!$delivery['files']): ?><p class="muted">Este envío no lleva archivos adjuntos.</p><?php else: ?><ul class="delivery-files"><?php foreach ($delivery['files'] as $file): ?><li><?= h($file['original_name']) ?><small><?= $file['page_count'] ? (int)$file['page_count'].' páginas · ' : '' ?><?= h(number_format((int)$file['size_bytes']/1024,1,',','.')) ?> KB</small></li><?php endforeach ?></ul><?php endif ?>
<details><summary>Identificador técnico del mensaje</summary><p class="delivery-message-id"><?= h($delivery['message_id']) ?></p></details>
</article><?php endforeach ?></section>
<?php if ($deliveryDetail['whatsapp']): ?><section class="card spaced"><h2>Mensajes de WhatsApp</h2><p class="muted">En esta sección, «Correo enviado» significa que WhatsApp aceptó el mensaje; no confirma su lectura.</p>
<?php foreach ($deliveryDetail['whatsapp'] as $message): ?><article class="mail-item"><div class="mail-top"><h3><?= ['history'=>'Historia clínica (PDF)','notice'=>'Radicado sin historia cargada','not_registered'=>'Respuesta «No registra»','response'=>'Respuesta «Aprobado»'][$message['kind']] ?? 'Mensaje' ?> · #<?= (int)$message['id'] ?></h3><span class="pill"><?= h($labels[$message['status']] ?? $message['status']) ?></span></div>
<dl class="delivery-facts"><div><dt>Número</dt><dd><?= h('+'.$message['phone']) ?></dd></div><div><dt>Intentos</dt><dd><?= (int)$message['attempts'] ?></dd></div><div><dt>Encolado</dt><dd><?= h($message['created_at']) ?></dd></div><div><dt>Aceptado</dt><dd><?= h($message['sent_at'] ?? 'Sin confirmación') ?></dd></div></dl>
<?php if ($message['last_error']): ?><div class="alert error"><strong>Motivo registrado:</strong> <?= h($message['last_error']) ?></div><?php endif ?></article><?php endforeach ?></section><?php endif ?>
</div></div>
<?php endif ?>
