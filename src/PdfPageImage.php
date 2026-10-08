<?php
declare(strict_types=1);
namespace App;

/**
 * Convierte una página de un PDF en PNG para enviarla por WhatsApp.
 * Usa lo que haya en el servidor: Imagick, pdftoppm (poppler-utils) o Ghostscript.
 * Sin ninguno devuelve null y el bot solo indica la página dentro del PDF.
 */
final class PdfPageImage
{
    private const DPI = 110;

    public static function available(): bool
    {
        return extension_loaded('imagick') || self::binary('pdftoppm') !== null || self::binary('gs') !== null;
    }

    /** Devuelve la ruta de un PNG temporal (el llamador debe borrarlo) o null si no se pudo generar. */
    public static function render(string $pdf, int $page): ?string
    {
        if (!is_file($pdf) || $page < 1) return null;
        $base = tempnam(sys_get_temp_dir(), 'cy');
        if ($base === false) return null;
        $png = $base . '.png';
        try {
            if (extension_loaded('imagick')) {
                $image = new \Imagick();
                $image->setResolution(self::DPI, self::DPI);
                $image->readImage($pdf . '[' . ($page - 1) . ']');
                $image->setImageBackgroundColor('white');
                $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $image->setImageFormat('png');
                $image->writeImage($png);
                $image->clear();
            } elseif (($bin = self::binary('pdftoppm')) !== null) {
                self::run([$bin, '-png', '-singlefile', '-r', (string)self::DPI, '-f', (string)$page, '-l', (string)$page, $pdf, $base]);
            } elseif (($bin = self::binary('gs')) !== null) {
                self::run([$bin, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=png16m', '-r' . self::DPI, '-dFirstPage=' . $page, '-dLastPage=' . $page, '-sOutputFile=' . $png, $pdf]);
            }
        } catch (\Throwable) { /* se limpia abajo */ }
        @unlink($base);
        if (is_file($png) && filesize($png) > 0 && filesize($png) <= 5 * 1024 * 1024) return $png;
        if (is_file($png)) @unlink($png);
        return null;
    }

    private static function binary(string $name): ?string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', 'C:\\Program Files\\poppler\\Library\\bin\\', 'C:\\Program Files\\gs\\bin\\'] as $dir) {
            foreach ([$name, $name . '.exe'] as $file) if (is_file($dir . $file) && is_executable($dir . $file)) return $dir . $file;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            foreach ([$name, $name . '.exe'] as $file) if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $file)) return $dir . DIRECTORY_SEPARATOR . $file;
        }
        return null;
    }

    private static function run(array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return;
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        proc_close($process);
    }
}
