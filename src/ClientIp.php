<?php
declare(strict_types=1);
namespace App;

/** IP del cliente para los límites de intentos. */
final class ClientIp
{
    public static function resolve(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '') return 'unknown';
        // Conexión directa desde una IP pública: no se confía en encabezados del cliente.
        if (self::isPublic($remote)) return $remote;
        // Detrás de Traefik (red privada): el proxy agrega la IP real al final de X-Forwarded-For.
        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($forwarded as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
        }
        return $remote;
    }

    private static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
