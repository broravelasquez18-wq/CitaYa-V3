<?php
declare(strict_types=1);
namespace App;

use Smalot\PdfParser\Parser;

/** Structured extraction for the text-based CEDIM / Indigo formats supplied. */
final class ClinicalPdfReader
{
    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 12 * 1024 * 1024 || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            throw new \DomainException('Adjunta un PDF válido de hasta 12 MB.');
        }
        try { $pages = (new Parser())->parseFile($path)->getPages(); }
        catch (\Throwable) { throw new \DomainException('No se pudo leer el PDF. Comprueba que no esté dañado o protegido.'); }
        if (!$pages || count($pages) > 150) throw new \DomainException('El PDF debe tener entre 1 y 150 páginas.');
        $texts = [];
        foreach ($pages as $page) $texts[] = $page->getText();
        return $this->extract($texts);
    }

    public function extract(array $texts): array
    {
        if (!$texts || trim(implode('', $texts)) === '') throw new \DomainException('El PDF no tiene texto legible. Los documentos escaneados necesitan OCR antes de cargarse.');
        $first = $texts[0];
        $normalized = self::normalize($first);
        if (!preg_match('/Tipo\s+documento:\s*(CC|CE|TI|RC|PA|PPT)\s*Numero:\s*([A-Z0-9]+)/i', $normalized, $identity)) {
            throw new \DomainException('No se pudo identificar el tipo y número de documento del paciente dentro del PDF.');
        }
        $type = strtoupper($identity[1]); $number = strtoupper($identity[2]);
        if (preg_match('/Identificaci[oó]n:\s*[A-Z0-9]+\s*Nombres:\s*(.*?)\s*Apellidos:\s*([^\r\n]+)/iu', $first, $names)) {
            $name = self::clean($names[1] . ' ' . $names[2]);
        } elseif (preg_match('/Apellidos:\s*([^\r\n]+)\s*Nombres:\s*(.*?)\s*Edad:/iu', $first, $names)) {
            $name = self::clean($names[2] . ' ' . $names[1]);
        } else { throw new \DomainException('No se pudo leer el nombre del paciente.'); }
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) throw new \DomainException('El nombre detectado no es válido.');
        if (!preg_match('/(?:^|\n|\d)Ingreso:\h*([A-Z0-9]{4,60})\b/i', $normalized, $admission)) {
            throw new \DomainException('No se pudo identificar el número de ingreso de la atención.');
        }
        // Only labelled attention dates are considered, never birth or print dates.
        if (!preg_match('/Fecha\s+ingreso:[^\r\n]*?(\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(\d{1,2}:\d{2}:\d{2})\s*([ap])?\.?\s*m?\.?)?/i', $normalized, $date)) {
            if (!preg_match('/Fecha\s+historia:\s*(\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(\d{1,2}:\d{2}:\d{2})\s*([ap])?\.?\s*m?\.?)?/i', $normalized, $date)) throw new \DomainException('No se pudo leer la fecha de atención.');
        }
        $day = self::parseDate($date[1]);
        if (!$day || $day->format('Y-m-d') > date('Y-m-d')) throw new \DomainException('La fecha de atención detectada no es válida.');
        $time = $date[2] ?? '00:00:00';
        [$hour,$minute,$second] = array_map('intval', explode(':', $time));
        if (!empty($date[3])) $hour = $hour % 12 + (strtolower($date[3]) === 'p' ? 12 : 0);
        if ($hour > 23 || $minute > 59 || $second > 59) throw new \DomainException('La hora detectada no es válida.');
        if (!preg_match('/Especialidad:\s*([^\r\n]+)/iu', $first, $specialty)) throw new \DomainException('No se pudo leer la especialidad del profesional de la atención.');
        $specialtyName = self::clean($specialty[1]);
        if (mb_strlen($specialtyName) > 120) throw new \DomainException('La especialidad detectada no es válida.');
        foreach ($texts as $text) {
            $norm = self::normalize($text); $found = [];
            preg_match_all('/Tipo\s+documento:\s*(CC|CE|TI|RC|PA|PPT)\s*Numero:\s*([A-Z0-9]+)/i', $norm, $pageTypes, PREG_SET_ORDER);
            foreach ($pageTypes as $item) {
                if (strtoupper($item[1]) !== $type || strtoupper($item[2]) !== $number) throw new \DomainException('El PDF contiene datos de más de un paciente. No se importó.');
                $found[] = $item[2];
            }
            preg_match_all('/Identificacion:\s*([A-Z0-9]+)\s*Nombres:/i', $norm, $pageIds);
            foreach ($pageIds[1] as $id) {
                if (strtoupper($id) !== $number) throw new \DomainException('El PDF contiene datos de más de un paciente. No se importó.');
                $found[] = $id;
            }
            preg_match_all('/(?:^|\n|\d)Ingreso:\h*([A-Z0-9]{4,60})\b/i', $norm, $pageAdmissions);
            foreach ($pageAdmissions[1] as $value) if ($value !== $admission[1]) throw new \DomainException('El PDF contiene más de un ingreso. Carga cada atención como un paquete separado.');
            preg_match_all('/Fecha\s+historia:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $norm, $pageDates);
            foreach ($pageDates[1] as $value) if (self::parseDate($value)?->format('Y-m-d') !== $day->format('Y-m-d')) throw new \DomainException('El PDF contiene fechas de atención diferentes. No se agruparon automáticamente.');
            if (!$found && (!in_array($admission[1], $pageAdmissions[1], true) || !in_array($day->format('Y-m-d'), array_map(fn($value) => self::parseDate($value)?->format('Y-m-d'), $pageDates[1]), true))) throw new \DomainException('Hay una página sin identificación ni referencia verificable a esta atención. Se necesita revisar su formato u obtener texto mediante OCR.');
        }
        preg_match('/Numero\s+de\s+folio:\s*([^\r\n]+)/i', $normalized, $folio);
        return ['document_type'=>$type,'document_number'=>$number,'full_name'=>$name,
            'attended_at'=>$day->format('Y-m-d') . sprintf(' %02d:%02d:%02d',$hour,$minute,$second),
            'admission'=>$admission[1],'folio'=>isset($folio[1]) ? mb_substr(self::clean($folio[1]),0,60) : null,
            'specialty'=>$specialtyName,'orders'=>MedicalOrders::detect($texts),'location'=>mb_substr(self::clean(explode("\n",$first)[0]),0,160), 'page_count'=>count($texts)];
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $parts)) return null;
        [$day, $month, $year] = array_map('intval', array_slice($parts, 1));
        if (!checkdate($month, $day, $year)) return null;
        return \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: null;
    }

    public static function normalize(string $text): string
    {
        return strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',"\r"=>'']);
    }
    private static function clean(string $text): string { return trim(preg_replace('/\s+/u',' ',$text)); }
}
