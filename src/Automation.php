<?php
declare(strict_types=1);
namespace App;

/**
 * Eventos de CitaYa hacia n8n (historia disponible, solicitud pendiente, fallo de envío…).
 * Se guardan en automation_events y los entrega bin/worker.php al webhook n8n_events_url, firmados con
 * X-CitaYa-Signature (HMAC-SHA256 del cuerpo con n8n_secret). Solo llevan radicados e identificadores, nunca
 * datos clínicos ni nombres. Sin n8n_events_url configurado no se registra nada.
 */
final class Automation
{
    private const MAX_ATTEMPTS = 6;

    public function __construct(private readonly Application $app) {}

    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public static function validSignature(string $body, string $header, string $secret): bool
    {
        return $secret !== '' && hash_equals(self::sign($body, $secret), trim($header));
    }

    public function enabled(): bool
    {
        return ($this->app->config['n8n_events_url'] ?? '') !== '' && ($this->app->config['n8n_secret'] ?? '') !== '';
    }

    public function emit(string $event, array $data, string $dedupe): void
    {
        if (!$this->enabled()) return;
        $this->app->db->run('INSERT IGNORE INTO automation_events (event,payload,dedupe_key,available_at,created_at) VALUES (?,?,?,?,?)', [
            $event, json_encode($data, JSON_UNESCAPED_UNICODE), substr(hash('sha256', $event . ':' . $dedupe), 0, 64), $this->app->now(), $this->app->now(),
        ]);
    }

    /** Entrega un evento pendiente a n8n. Devuelve false si no había trabajo. */
    public function tick(): bool
    {
        if (!$this->enabled()) return false;
        $db = $this->app->db;
        $event = $db->transaction(function () use ($db) {
            $event = $db->one("SELECT * FROM automation_events WHERE status IN ('queued','retry') AND available_at<=? ORDER BY id LIMIT 1 FOR UPDATE", [$this->app->now()]);
            if ($event) $db->run("UPDATE automation_events SET status='sending',attempts=attempts+1 WHERE id=?", [$event['id']]);
            return $event;
        });
        if (!$event) return false;
        $body = json_encode(['id' => (int)$event['id'], 'event' => $event['event'], 'data' => json_decode($event['payload'], true), 'created_at' => $event['created_at'], 'ts' => time()], JSON_UNESCAPED_UNICODE);
        $curl = curl_init($this->app->config['n8n_events_url']);
        curl_setopt_array($curl, [
            // 120 s: n8n responde después de pedir el envío, que puede incluir PDF.
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CitaYa-Signature: ' . self::sign($body, $this->app->config['n8n_secret'])],
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $attempts = (int)$event['attempts'] + 1;
        if ($raw !== false && $status >= 200 && $status < 300) {
            $db->run("UPDATE automation_events SET status='sent',sent_at=?,last_error=NULL WHERE id=?", [$this->app->now(), $event['id']]);
        } else {
            $db->run('UPDATE automation_events SET status=?,last_error=?,available_at=? WHERE id=?', [
                $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry', $raw === false ? 'network_error' : 'http_' . $status,
                date('Y-m-d H:i:s', time() + 30 * 2 ** min($attempts, 6)), $event['id'],
            ]);
        }
        return true;
    }
}
