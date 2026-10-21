<?php
declare(strict_types=1);
namespace App;

/**
 * OCR de páginas escaneadas: pdftoppm (poppler) convierte la página en imagen y Tesseract lee el texto en español.
 * Solo se usa en las primeras páginas de un PDF, para completar los datos del paciente y de la atención.
 * Si las herramientas no están instaladas, available() es false y el lector sigue sin OCR.
 */
final class PdfOcr
{
    public function __construct(private readonly string $pdftoppm = 'pdftoppm', private readonly string $tesseract = 'tesseract', private readonly int $timeout = 60) {}

    public static function fromConfig(array $config): ?self
    {
        if (empty($config['ocr_enabled'])) return null;
        $ocr = new self((string)($config['ocr_pdftoppm'] ?? 'pdftoppm'), (string)($config['ocr_tesseract'] ?? 'tesseract'), (int)($config['ocr_timeout'] ?? 60));
        return $ocr->available() ? $ocr : null;
    }

    public function available(): bool
    {
        static $cache = [];
        $key = $this->pdftoppm . '|' . $this->tesseract;
        return $cache[$key] ??= self::run([$this->pdftoppm, '-v'], 10) !== null && self::run([$this->tesseract, '--version'], 10) !== null;
    }

    /** Texto de una página (1 = primera); cadena vacía si no se pudo leer. */
    public function page(string $pdf, int $page): string
    {
        $dir = sys_get_temp_dir() . '/citaya-ocr-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) return '';
        try {
            if (self::run([$this->pdftoppm, '-f', (string)$page, '-l', (string)$page, '-r', '300', '-gray', '-png', $pdf, $dir . '/page'], $this->timeout) === null) return '';
            $images = glob($dir . '/page*.png') ?: [];
            if (!$images) return '';
            return self::run([$this->tesseract, $images[0], 'stdout', '-l', 'spa', '--psm', '3'], $this->timeout) ?? '';
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
            @rmdir($dir);
        }
    }

    /** Ejecuta un programa sin shell; devuelve su salida o null si falla o se pasa del tiempo. */
    private static function run(array $command, int $timeout): ?string
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return null;
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $deadline = microtime(true) + $timeout;
        while (true) {
            $output .= (string)stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) { proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); return null; }
            usleep(20000);
        }
        $output .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $code;
        return $exit === 0 ? $output : null;
    }
}
