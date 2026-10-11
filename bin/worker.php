<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$worker = new App\MailWorker($app);
$whatsapp = $app->whatsapp();
$automation = new App\Automation($app);
$loop = in_array('--loop', $argv, true);
$jobId = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--loop') continue;
    if (!preg_match('/^--job=([1-9][0-9]*)$/D', $argument, $match) || $jobId !== null || !filter_var($match[1], FILTER_VALIDATE_INT)) {
        fwrite(STDERR, "Uso: php bin/worker.php [--loop | --job=ID]\n"); exit(1);
    }
    $jobId = (int)$match[1];
}
if ($jobId !== null) {
    if ($loop) { fwrite(STDERR, "El envío individual no admite --loop.\n"); exit(1); }
    $processed = $worker->tick($jobId);
    echo $processed ? "Envío seleccionado procesado.\n" : "El envío seleccionado no está pendiente o aún no está disponible.\n";
    exit;
}
$heartbeat = 0;
do {
    $processed = 0;
    // Latido para el monitoreo de n8n (system.health): como máximo una escritura por minuto.
    if (time() - $heartbeat >= 60) { $app->releaseDeferredNotices(); $app->db->run('INSERT INTO system_state (name,value,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)', ['worker_heartbeat', $loop ? 'loop' : 'once', $app->now()]); $heartbeat = time(); }
    while ($processed < 50 && $worker->tick()) $processed++;
    // Un solo responsable de la cola de WhatsApp: con whatsapp_dispatch = n8n, los envíos los pide n8n por /n8n.php.
    if (($app->config['whatsapp_dispatch'] ?? 'worker') !== 'n8n') while ($processed < 100 && $whatsapp->tick()) $processed++;
    while ($processed < 150 && $automation->tick()) $processed++;
    if ($processed) echo date('c') . " Procesados: $processed\n";
    if ($loop) sleep(3);
} while ($loop);
