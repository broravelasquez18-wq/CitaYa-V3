<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$worker = new App\MailWorker($app);
$whatsapp = $app->whatsapp();
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
do {
    $processed = 0;
    while ($processed < 50 && $worker->tick()) $processed++;
    while ($processed < 100 && $whatsapp->tick()) $processed++;
    if ($processed) echo date('c') . " Procesados: $processed\n";
    if ($loop) sleep(3);
} while ($loop);
