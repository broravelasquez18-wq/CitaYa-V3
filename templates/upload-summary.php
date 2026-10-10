<?php
/** Resumen de lo que el sistema leyó y hizo con un paquete. Espera $loadSummary (Repository::summary). */
$s = $loadSummary;
$sent = array_values(array_filter($s['resolved'], fn($r) => $r['sent']));
$waiting = array_values(array_filter($s['resolved'], fn($r) => !$r['sent']));
?>
<div class="load-summary">
<dl class="load-data">
<div><dt>Paciente</dt><dd><?= h($s['patient']) ?><small><?= h($s['document']) ?></small></dd></div>
<div><dt>Atención</dt><dd><?= h($s['specialty']) ?><small><?= h(date('d/m/Y', strtotime($s['date']))) ?> · Ingreso <?= h($s['admission']) ?></small></dd></div>
<div><dt>Documentos</dt><dd><?= (int)$s['files'] ?> PDF<?= $s['pages'] ? ' · ' . (int)$s['pages'] . ((int)$s['pages'] === 1 ? ' página' : ' páginas') : '' ?><small>Versión <?= (int)$s['version'] ?></small></dd></div>
</dl>
<ul class="load-actions">
<li class="<?= $s['reused'] ? 'info' : 'done' ?>"><?= $s['reused'] ? 'Estos mismos archivos ya estaban registrados: no se duplicaron y la historia sigue disponible.' : ($s['version'] > 1 ? 'Se guardó como versión ' . (int)$s['version'] . ' y reemplaza a la anterior en las nuevas entregas.' : 'Historia guardada y disponible para las solicitudes de este paciente.') ?></li>
<li class="<?= $s['orders'] ? 'done' : 'info' ?>"><?= $s['orders'] ? 'Órdenes detectadas: ' . h(implode(', ', $s['orders'])) . '. Si el paciente la pide por WhatsApp, recibirá una guía de cada una.' : 'No se detectaron órdenes médicas (fórmula, laboratorio, imágenes…).' ?></li>
<?php if ($sent): ?><li class="done">Se envió automáticamente a <?= count($sent) === 1 ? 'la solicitud pendiente' : count($sent) . ' solicitudes pendientes' ?> <?= h(implode(', ', array_column($sent, 'reference'))) ?> por WhatsApp y correo.</li><?php endif ?>
<?php if ($waiting): ?><li class="warn">No se pudo enviar sola a: <?= h(implode(', ', array_column($waiting, 'reference'))) ?>. Revisa en Solicitudes → Pendientes.</li><?php endif ?>
<?php if (!$s['resolved']): ?><li class="info">No había solicitudes de WhatsApp esperando esta historia. Quedará lista para cuando el paciente la pida.</li><?php endif ?>
</ul>
</div>
