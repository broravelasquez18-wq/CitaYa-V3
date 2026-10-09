<?php
declare(strict_types=1);
// Configuración inicial de Zernio (requiere zernio_api_key en config/local.php):
//   php bin/zernio.php accounts   lista los números de WhatsApp conectados (valor para zernio_account_id)
//   php bin/zernio.php webhook    registra <public_url>/whatsapp.php para message.received, firmado con zernio_webhook_secret
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $app->config;
if ($config['zernio_api_key'] === '') { fwrite(STDERR, "Falta zernio_api_key en config/local.php.\n"); exit(1); }

function zernio(array $config, string $method, string $path, ?array $body = null, bool $fatal = true): array
{
    $curl = curl_init(rtrim($config['zernio_api_url'], '/') . $path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['zernio_api_key'], 'Accept: application/json', 'Content-Type: application/json'],
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $response = json_decode((string)$raw, true) ?: [];
    if ($raw === false || $status < 200 || $status >= 300) {
        fwrite(STDERR, $raw === false ? "No hubo conexión con Zernio.\n" : "Zernio respondió $status: " . ($response['error'] ?? 'sin detalle') . "\n");
        if ($fatal) exit(1);
    }
    return $response;
}

$command = $argv[1] ?? '';
if ($command === 'accounts') {
    $accounts = zernio($config, 'GET', '/v1/accounts?platform=whatsapp')['accounts'] ?? [];
    if (!$accounts) { echo "No hay números de WhatsApp conectados. Conéctalo en el panel de Zernio.\n"; exit; }
    foreach ($accounts as $account) {
        printf("%s  %s  %s%s\n", $account['_id'], $account['displayName'] ?? '', $account['username'] ?? '', ($account['isActive'] ?? true) ? '' : '  (inactivo)');
    }
    echo "\nCopia el id en zernio_account_id de config/local.php.\n";
} elseif ($command === 'webhook') {
    $url = rtrim($config['public_url'], '/') . '/whatsapp.php';
    if (!str_starts_with($url, 'https://')) { fwrite(STDERR, "public_url debe ser HTTPS y accesible desde internet (por ejemplo, un túnel).\n"); exit(1); }
    if (strlen((string)$config['zernio_webhook_secret']) < 32) { fwrite(STDERR, "zernio_webhook_secret debe tener al menos 32 caracteres. Genera uno con: php -r \"echo bin2hex(random_bytes(24));\"\n"); exit(1); }
    if ($config['zernio_account_id'] === '') { fwrite(STDERR, "Falta zernio_account_id (ver: php bin/zernio.php accounts).\n"); exit(1); }
    $settings = ['secret' => $config['zernio_webhook_secret'], 'events' => ['message.received'], 'accountIds' => [$config['zernio_account_id']], 'isActive' => true];
    $id = '';
    foreach (zernio($config, 'GET', '/v1/webhooks/settings')['webhooks'] ?? [] as $webhook) {
        if (($webhook['url'] ?? '') === $url) $id = (string)$webhook['_id'];
    }
    if ($id !== '') {
        // Ya existía: se sincroniza el secreto y la cuenta por si cambiaron en la configuración.
        zernio($config, 'PUT', '/v1/webhooks/settings', ['webhookId' => $id] + $settings);
        echo "Webhook actualizado: $url (id $id).\n";
    } else {
        $created = zernio($config, 'POST', '/v1/webhooks/settings', ['name' => 'CitaYa WhatsApp', 'url' => $url] + $settings);
        $id = (string)($created['webhook']['_id'] ?? '');
        echo "Webhook registrado: $url (id $id).\n";
    }
    if ($id !== '') {
        // La prueba no es obligatoria: si falla, el webhook ya quedó registrado.
        $test = zernio($config, 'POST', '/v1/webhooks/test', ['webhookId' => $id], false);
        echo ($test['success'] ?? false) ? "Prueba de entrega: correcta.\n" : "Prueba de entrega fallida: revisa que la URL responda (se puede repetir desde el panel de Zernio).\n";
    }
} else {
    fwrite(STDERR, "Uso: php bin/zernio.php accounts | webhook\n"); exit(1);
}
