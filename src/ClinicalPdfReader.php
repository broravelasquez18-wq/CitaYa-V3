<?php
declare(strict_types=1);
namespace App;

use Smalot\PdfParser\Parser;

/** Structured extraction for the text-based CEDIM / Indigo formats supplied. */
final class ClinicalPdfReader
{
    /** Páginas iniciales donde se buscan los datos del paciente y de la atención (y que se pasan por OCR si son escaneadas). */
    public const LEAD_PAGES = 3;

    /** Datos que se buscan en las primeras páginas y que una persona puede completar a mano si faltan. */
    public const FIELDS = ['document' => 'el tipo y número de documento', 'full_name' => 'el nombre del paciente', 'admission' => 'el número de ingreso',
        'attended_at' => 'la fecha de atención', 'specialty' => 'la especialidad'];
    public const DOCUMENT_TYPES = ['CC', 'CE', 'TI', 'RC', 'PA', 'PPT'];

    public function __construct(private readonly ?PdfOcr $ocr = null) {}

    /** $manual: datos escritos a mano (ver manual()); solo completan los que el PDF no trae. */
    public function read(string $path, array $manual = []): array
    {
        if (!is_file($path) || filesize($path) > 12 * 1024 * 1024 || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            throw new \DomainException('Adjunta un PDF válido de hasta 12 MB.');
        }
        try { $pages = (new Parser())->parseFile($path)->getPages(); }
        catch (\Throwable) { throw new \DomainException('No se pudo leer el PDF. Comprueba que no esté dañado o protegido.'); }
        if (!$pages || count($pages) > 150) throw new \DomainException('El PDF debe tener entre 1 y 150 páginas.');
        $texts = [];
        foreach ($pages as $page) $texts[] = $page->getText();
        // Las primeras páginas escaneadas (sin texto) se leen con OCR para obtener los datos del paciente y de la atención.
        $ocrPages = [];
        if ($this->ocr) {
            foreach (array_slice(array_keys($texts), 0, self::LEAD_PAGES) as $index) {
                if (trim($texts[$index]) !== '') continue;
                $text = $this->ocr->page($path, $index + 1);
                if (trim($text) !== '') { $texts[$index] = $text; $ocrPages[] = $index; }
            }
        }
        return $this->extract($texts, $ocrPages, $manual);
    }

    /**
     * $ocrPages: índices de páginas leídas con OCR. Solo sirven para encontrar datos que falten: no se usan para
     * rechazar el PDF por «otro paciente» u «otro ingreso», porque el OCR puede leer mal un número.
     */
    public function extract(array $texts, array $ocrPages = [], array $manual = []): array
    {
        $texts = array_values($texts);
        if (!$texts) throw new ClinicalPdfException('El PDF no tiene páginas.');
        $noText = trim(implode('', $texts)) === '';
        $lead = array_slice($texts, 0, self::LEAD_PAGES, true);
        $leadLabel = count($lead) === 1 ? 'en la página 1' : 'en las primeras páginas (1 a ' . count($lead) . ')';
        // Cada dato se toma de la primera de las páginas iniciales que lo traiga.
        $find = static function (string $pattern, bool $normalize = true) use ($lead): ?array {
            foreach ($lead as $index => $text) if (preg_match($pattern, $normalize ? self::normalize($text) : $text, $match)) return [$match, $index + 1];
            return null;
        };
        $manual = self::manual($manual);
        $missing = []; $found = [];
        // Documento: del PDF; si falta, el escrito a mano.
        $identity = $find('/Tipo\s+documento:\s*(CC|CE|TI|RC|PA|PPT)\s*Numero:\s*([A-Z0-9]+)/i');
        if ($identity) { [[, $type, $number], $identityPage] = $identity; $type = strtoupper($type); $number = strtoupper($number); $found['document'] = $type . ' ' . $number; }
        elseif (isset($manual['document_type'], $manual['document_number'])) { $type = $manual['document_type']; $number = $manual['document_number']; $identityPage = 1; }
        else { $missing[] = 'document'; $identityPage = 1; }
        if ($names = $find('/Identificaci[oó]n:\s*[A-Z0-9]+\s*Nombres:\s*(.*?)\s*Apellidos:\s*([^\r\n]+)/iu', false)) {
            $name = self::clean($names[0][1] . ' ' . $names[0][2]);
        } elseif ($names = $find('/Apellidos:\s*([^\r\n]+)\s*Nombres:\s*(.*?)\s*Edad:/iu', false)) {
            $name = self::clean($names[0][2] . ' ' . $names[0][1]);
        } else { $name = $manual['full_name'] ?? null; }
        if ($name === null) $missing[] = 'full_name';
        elseif ($names) { if (mb_strlen($name) < 3 || mb_strlen($name) > 160) throw new ClinicalPdfException('El nombre detectado no es válido.', $names[1]); $found['full_name'] = $name; }
        $admissionFound = $find('/(?:^|\n|\d)Ingreso:\h*([A-Z0-9]{4,60})\b/i');
        if ($admissionFound) { $admission = $admissionFound[0]; $found['admission'] = $admission[1]; }
        elseif (isset($manual['admission'])) $admission = [0 => '', 1 => $manual['admission']];
        else $missing[] = 'admission';
        // Solo fechas rotuladas de la atención, nunca la de nacimiento ni la de impresión.
        $dateFound = $find('/Fecha\s+ingreso:[^\r\n]*?(\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(\d{1,2}:\d{2}:\d{2})\s*([ap])?\.?\s*m?\.?)?/i')
            ?? $find('/Fecha\s+historia:\s*(\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(\d{1,2}:\d{2}:\d{2})\s*([ap])?\.?\s*m?\.?)?/i');
        if ($dateFound) {
            [$date, $datePage] = $dateFound;
            $day = self::parseDate($date[1]);
            if (!$day || $day->format('Y-m-d') > date('Y-m-d')) throw new ClinicalPdfException('La fecha de atención detectada (' . $date[1] . ') no es válida.', $datePage);
            $time = $date[2] ?? '00:00:00';
            [$hour,$minute,$second] = array_map('intval', explode(':', $time));
            if (!empty($date[3])) $hour = $hour % 12 + (strtolower($date[3]) === 'p' ? 12 : 0);
            if ($hour > 23 || $minute > 59 || $second > 59) throw new ClinicalPdfException('La hora detectada no es válida.', $datePage);
            $found['attended_at'] = $day->format('d/m/Y');
        } elseif (isset($manual['attended_at'])) { $day = $manual['attended_at']; $hour = $minute = $second = 0; }
        else $missing[] = 'attended_at';
        $specialty = $find('/Especialidad:\s*([^\r\n]+)/iu', false);
        if ($specialty) {
            $specialtyName = self::clean($specialty[0][1]);
            if (mb_strlen($specialtyName) > 120) throw new ClinicalPdfException('La especialidad detectada no es válida.', $specialty[1]);
            $found['specialty'] = $specialtyName;
        } elseif (isset($manual['specialty'])) $specialtyName = $manual['specialty'];
        else $missing[] = 'specialty';
        if ($missing) throw new ClinicalPdfMissingData($missing, $found, $noText ? 'porque el PDF no tiene texto legible' . ($this->ocr ? ' ni con OCR' : ' (es escaneado)') : $leadLabel);
        $first = $texts[$identityPage - 1];
        $normalized = self::normalize($first);
        foreach ($texts as $index => $text) {
            $page = $index + 1;
            // Páginas sin texto (anexos escaneados) o leídas con OCR: se toman como parte de la atención de las primeras páginas.
            if (trim($text) === '' || in_array($index, $ocrPages, true)) continue;
            $norm = self::normalize($text);
            preg_match_all('/Tipo\s+documento:\s*(CC|CE|TI|RC|PA|PPT)\s*Numero:\s*([A-Z0-9]+)/i', $norm, $pageTypes, PREG_SET_ORDER);
            foreach ($pageTypes as $item) {
                if (strtoupper($item[1]) !== $type || strtoupper($item[2]) !== $number) throw new ClinicalPdfException('es de otro paciente (' . strtoupper($item[1]) . ' ' . strtoupper($item[2]) . '); el PDF empieza con ' . $type . ' ' . $number . '. Separa los PDF por paciente.', $page);
            }
            preg_match_all('/Identificacion:\s*([A-Z0-9]+)\s*Nombres:/i', $norm, $pageIds);
            foreach ($pageIds[1] as $id) {
                if (strtoupper($id) !== $number) throw new ClinicalPdfException('es de otro paciente (identificación ' . strtoupper($id) . '); el PDF empieza con ' . $type . ' ' . $number . '. Separa los PDF por paciente.', $page);
            }
            $stamp = self::orderStamp($norm);
            preg_match_all('/(?:^|\n|\d)Ingreso:\h*([A-Z0-9]{4,60})\b/i', $norm, $pageAdmissions);
            if ($stamp) $pageAdmissions[1][] = $stamp[1];
            foreach ($pageAdmissions[1] as $value) if ($value !== $admission[1]) throw new ClinicalPdfException('es de otro ingreso (' . $value . '); el PDF empieza con el ingreso ' . $admission[1] . '. Carga cada atención en un PDF aparte.', $page);
            preg_match_all('/Fecha\s+historia:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $norm, $pageDates);
            if ($stamp) $pageDates[1][] = $stamp[0];
            foreach ($pageDates[1] as $value) if (self::parseDate($value)?->format('Y-m-d') !== $day->format('Y-m-d')) throw new ClinicalPdfException('tiene otra fecha de atención (' . $value . '); el PDF empieza con el ' . $day->format('d/m/Y') . '. Carga cada atención en un PDF aparte.', $page);
            // Un anexo sin datos del paciente ni de la atención se toma como parte de la atención de las primeras páginas.
        }
        preg_match('/Numero\s+de\s+folio:\s*([^\r\n]+)/i', $normalized, $folio);
        return ['document_type'=>$type,'document_number'=>$number,'full_name'=>$name,
            'attended_at'=>$day->format('Y-m-d') . sprintf(' %02d:%02d:%02d',$hour,$minute,$second),
            'admission'=>$admission[1],'folio'=>isset($folio[1]) ? mb_substr(self::clean($folio[1]),0,60) : null,
            'specialty'=>$specialtyName,'orders'=>MedicalOrders::detect($texts),'location'=>mb_substr(self::clean(explode("\n",$first)[0]),0,160), 'page_count'=>count($texts), 'ocr_pages'=>array_map(fn($index) => $index + 1, $ocrPages)];
    }

    /**
     * Órdenes y fórmulas de Indigo: el texto del encabezado sale como «Pagina 2/226/08/2026 8:11:52 a. m.2A4F26BDB4»
     * (página, fecha de la historia con hora e ingreso pegados) y las etiquetas «Fecha Historia:Ingreso:» quedan vacías.
     * Devuelve [fecha, ingreso]. La fecha va con ceros (dd/mm/aaaa), así no se confunde con el «N/M» de la página.
     */
    private static function orderStamp(string $norm): ?array
    {
        if (!preg_match('/Fecha\s+historia:\s*Ingreso:/i', $norm)) return null;
        if (!preg_match('/Pagina\h*\d+\/\d+\h*(\d{2}\/\d{2}\/\d{4})\h+\d{1,2}:\d{2}:\d{2}\h*[ap]\.?\h*m\.?\h*([A-Z0-9]{4,60})\h*$/im', $norm, $stamp)) return null;
        return [$stamp[1], $stamp[2]];
    }

    /**
     * Valida los datos escritos a mano: document_type, document_number, full_name, admission, attended_at (AAAA-MM-DD
     * o DD/MM/AAAA) y specialty. Lo vacío se ignora; lo inválido se rechaza con un mensaje para la persona.
     */
    public static function manual(array $input): array
    {
        $value = fn(string $key): string => trim((string)($input[$key] ?? ''));
        $data = [];
        if ($value('document_number') !== '' || $value('document_type') !== '') {
            $type = strtoupper($value('document_type')); $number = strtoupper(preg_replace('/[\s.\-]/', '', $value('document_number')));
            if (!in_array($type, self::DOCUMENT_TYPES, true)) throw new \DomainException('Elige el tipo de documento.');
            if (!preg_match('/^[A-Z0-9]{3,20}$/', $number)) throw new \DomainException('El número de documento debe tener entre 3 y 20 letras o números, sin espacios.');
            $data['document_type'] = $type; $data['document_number'] = $number;
        }
        if ($value('full_name') !== '') {
            $name = self::clean($value('full_name'));
            if (mb_strlen($name) < 3 || mb_strlen($name) > 160 || !preg_match('/^[\p{L}][\p{L}\s\'.-]+$/u', $name)) throw new \DomainException('Escribe el nombre completo del paciente, solo con letras.');
            $data['full_name'] = mb_strtoupper($name);
        }
        if ($value('admission') !== '') {
            $admission = strtoupper(preg_replace('/\s/', '', $value('admission')));
            if (!preg_match('/^[A-Z0-9]{4,60}$/', $admission)) throw new \DomainException('El número de ingreso debe tener entre 4 y 60 letras o números.');
            $data['admission'] = $admission;
        }
        if ($value('attended_at') !== '') {
            $raw = $value('attended_at');
            $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : self::parseDate($raw);
            if (!$day || $day->format('Y-m-d') > date('Y-m-d') || $day->format('Y') < '1950') throw new \DomainException('Escribe una fecha de atención válida que no sea futura.');
            $data['attended_at'] = $day;
        }
        if ($value('specialty') !== '') {
            $specialty = self::clean($value('specialty'));
            if (mb_strlen($specialty) < 3 || mb_strlen($specialty) > 120) throw new \DomainException('Escribe la especialidad (entre 3 y 120 caracteres).');
            $data['specialty'] = mb_strtoupper($specialty);
        }
        return $data;
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
