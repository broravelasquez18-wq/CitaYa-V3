<?php
declare(strict_types=1);
// Detecta órdenes médicas en los PDF ya cargados antes de existir esta función.
// Uso: php bin/detect-orders.php   (se puede repetir: solo procesa archivos sin órdenes registradas)
//      php bin/detect-orders.php --refresh   (vuelve a leer todos los archivos: nuevos formatos y detalle de cada orden)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $app->db; $done = 0; $withOrders = 0; $failed = 0;
$refresh = in_array('--refresh', $argv, true);
$files = $db->all("SELECT f.id,f.storage_name,f.sha256 FROM clinical_files f" . ($refresh ? '' : ' WHERE NOT EXISTS (SELECT 1 FROM clinical_orders o WHERE o.file_id=f.id)') . ' ORDER BY f.id');
foreach ($files as $file) {
    $path = $app->config['storage_path'] . '/histories/' . $file['storage_name'];
    if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $file['storage_name']) || !is_file($path)) { $failed++; continue; }
    try {
        $texts = array_map(fn($page) => $page->getText(), (new Smalot\PdfParser\Parser())->parseFile($path)->getPages());
    } catch (Throwable) { $failed++; continue; }
    $orders = App\MedicalOrders::detect($texts);
    $db->transaction(function () use ($db, $file, $orders) {
        $db->run('DELETE FROM clinical_orders WHERE file_id=?', [$file['id']]);
        foreach ($orders as $order) $db->insert('clinical_orders', ['file_id' => $file['id'], 'order_type' => $order['type'], 'page' => $order['page'], 'details' => json_encode($order['details'], JSON_UNESCAPED_UNICODE)]);
    });
    $done++; if ($orders) $withOrders++;
}
echo "Archivos revisados: $done. Con órdenes: $withOrders. No se pudieron leer: $failed.\n";
