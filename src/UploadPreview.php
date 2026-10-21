<?php
declare(strict_types=1);
namespace App;

/**
 * Vista previa de los PDF del resultado de la última carga. Cada archivo se identifica con una referencia:
 * «f<id>» para un PDF guardado en storage/histories y «t<token>» para la copia temporal de un PDF que no se
 * pudo cargar (se borra a las 24 h). Solo se abren referencias que figuran en el resultado de la sesión actual.
 */
final class UploadPreview
{
    private const TTL = 86400;

    public function __construct(private readonly Application $app) {}

    /** Copia temporal de un PDF rechazado para poder ver la página con el problema. */
    public function keep(string $path, string $name): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        foreach (glob($directory . '/*.pdf') ?: [] as $old) if (filemtime($old) < time() - self::TTL) @unlink($old);
        $token = bin2hex(random_bytes(16));
        if (!is_file($path) || !copy($path, $directory . '/' . $token . '.pdf')) return ['name' => $name];
        return ['ref' => 't' . $token, 'name' => $name];
    }

    /** Referencias abribles: las del resultado individual o masivo que está en la sesión. */
    public static function refs(array $session): array
    {
        $lists = [];
        if (is_array($session['single_upload_result'] ?? null)) $lists[] = $session['single_upload_result']['previews'] ?? [];
        foreach ($session['bulk_report']['results'] ?? [] as $result) $lists[] = $result['previews'] ?? [];
        return array_values(array_filter(array_column(array_merge(...$lists ?: [[]]), 'ref')));
    }

    /** Ruta y nombre del PDF de una referencia permitida, o null. */
    public function resolve(string $ref, array $session): ?array
    {
        if (!in_array($ref, self::refs($session), true)) return null;
        if (preg_match('/^t([a-f0-9]{32})$/', $ref, $match)) {
            $path = $this->directory() . '/' . $match[1] . '.pdf';
            $name = '';
            foreach ($session['bulk_report']['results'] ?? [] as $result) foreach ($result['previews'] ?? [] as $preview) if (($preview['ref'] ?? '') === $ref) $name = $preview['name'];
            return is_file($path) && filemtime($path) >= time() - self::TTL ? ['path' => $path, 'name' => $name] : null;
        }
        if (!preg_match('/^f(\d+)$/', $ref, $match)) return null;
        $file = $this->app->db->one('SELECT storage_name,original_name FROM clinical_files WHERE id=?', [(int)$match[1]]);
        if (!$file || !preg_match('/^[a-f0-9]{32}\.pdf$/', $file['storage_name'])) return null;
        $path = $this->app->config['storage_path'] . '/histories/' . $file['storage_name'];
        return is_file($path) ? ['path' => $path, 'name' => $file['original_name']] : null;
    }

    private function directory(): string { return $this->app->config['storage_path'] . '/upload-preview'; }
}
