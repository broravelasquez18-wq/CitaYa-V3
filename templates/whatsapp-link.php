<?php
// Vinculación del WhatsApp por QR desde el panel (solo administradores). Consulta el servicio QR por la red interna.
$curl = curl_init(rtrim((string)$app->config['whatsapp_qr_url'], '/') . '/link');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$raw = curl_exec($curl); $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
$link = $raw !== false && $code === 200 ? (json_decode((string)$raw, true) ?: []) : null;
$qr = is_array($link) && is_string($link['qr'] ?? null) && str_starts_with($link['qr'], 'data:image/png;base64,') ? $link['qr'] : null;
?>
<?php if (!$link || empty($link['connected'])): ?><meta http-equiv="refresh" content="5"><?php endif ?>
<section class="card wa-link">
<h2>WhatsApp del bot</h2>
<?php if ($link === null): ?>
<div class="alert error">No se pudo consultar el servicio de WhatsApp. Revisa que el contenedor <code>whatsapp-qr</code> esté encendido.</div>
<?php elseif (!empty($link['connected'])): ?>
<p><span class="pill sla-ok">Conectado</span> El bot está vinculado y atendiendo mensajes.</p>
<p class="muted">Para desvincularlo, cierra la sesión del dispositivo «CitaYa» en WhatsApp → Dispositivos vinculados.</p>
<?php else: ?>
<p class="muted"><?= h((string)($link['status'] ?? '')) ?></p>
<ol class="wa-steps"><li>En el celular del bot abre WhatsApp → <strong>Dispositivos vinculados</strong> → <strong>Vincular un dispositivo</strong>.</li><li>Escanea este código. Se renueva solo cada pocos segundos; esta página se actualiza sola.</li></ol>
<?php if ($qr): ?><img class="wa-qr" src="<?= h($qr) ?>" alt="Código QR para vincular WhatsApp" width="300" height="300"><?php else: ?><p class="muted">Generando el código…</p><?php endif ?>
<?php if (!empty($link['pairingCode'])): ?><p class="muted">O, en «Vincular con el número de teléfono», escribe: <strong class="wa-pair"><?= h((string)$link['pairingCode']) ?></strong></p><?php endif ?>
<?php endif ?>
</section>
