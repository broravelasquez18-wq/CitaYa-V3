<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/ClientIp.php';
use App\ClientIp;
function ipAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK $message\n";
}
// Datos ficticios: direcciones de documentación (RFC 5737 y RFC 3849).
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'203.0.113.7']) === '203.0.113.7', 'IP pública directa');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'203.0.113.7','HTTP_X_FORWARDED_FOR'=>'198.51.100.9']) === '203.0.113.7', 'IP pública directa ignora X-Forwarded-For');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'198.51.100.9']) === '198.51.100.9', 'Proxy privado con X-Forwarded-For');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'1.1.1.1, 198.51.100.9']) === '198.51.100.9', 'X-Forwarded-For con varias IP toma la última');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'2001:db8::5']) === '2001:db8::5', 'X-Forwarded-For con IPv6');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'198.51.100.9, basura']) === '198.51.100.9', 'Salta una entrada final inválida');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'no-es-ip']) === '10.0.1.48', 'X-Forwarded-For inválido conserva REMOTE_ADDR');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48','HTTP_X_FORWARDED_FOR'=>'']) === '10.0.1.48', 'X-Forwarded-For vacío conserva REMOTE_ADDR');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'10.0.1.48']) === '10.0.1.48', 'Proxy sin X-Forwarded-For conserva REMOTE_ADDR');
ipAssert(ClientIp::resolve(['REMOTE_ADDR'=>'127.0.0.1']) === '127.0.0.1', 'XAMPP local sin proxy no cambia');
ipAssert(ClientIp::resolve([]) === 'unknown', 'Sin REMOTE_ADDR');
echo "client-ip OK\n";
