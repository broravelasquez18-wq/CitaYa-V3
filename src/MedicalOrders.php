<?php
declare(strict_types=1);
namespace App;

/**
 * Detecta en el texto de cada página los títulos de órdenes médicas (fórmula, laboratorio, etc.)
 * y entrega la guía administrativa que el bot le muestra al paciente.
 * Solo se reconocen títulos al inicio de línea, para no confundir una orden con una nota
 * clínica que simplemente menciona la palabra «fórmula».
 */
final class MedicalOrders
{
    /** tipo => [patrón del título sin tildes y en minúsculas, etiqueta, guía para el paciente] */
    public const TYPES = [
        // Además de los títulos genéricos, los formatos de historia «SOLICITUD … EXTRAMURAL» / «SOLICITUD MEDICAMENTOS AMBULATORIOS».
        // Los campos del resumen («LABORATORIOS EXTRAMURAL:») no cuentan: pueden aparecer vacíos.
        'formula' => ['(?:formula(?:s)? medica(?:s)?|formulacion medica|prescripcion medica|formula de medicamentos|solicitud (?:de )?medicamentos(?: ambulatorios| extramural(?:es)?)?)', 'Fórmula médica',
            'Con esta fórmula puedes reclamar tus medicamentos en tu farmacia. Preséntala impresa o desde la imagen que te enviamos.'],
        'laboratorio' => ['(?:orden(?:es)? de laboratorio(?: clinico)?|orden(?:es)? de examen(?:es)?(?: de laboratorio)?|solicitud (?:de )?laboratorio(?:s)?(?: clinico(?:s)?)?(?: extramural(?:es)?)?)', 'Orden de laboratorio',
            'Preséntala en el laboratorio para realizarte los exámenes indicados.'],
        'imagenes' => ['(?:orden(?:es)? de (?:imagen(?:es)?(?: diagnostica(?:s)?)?|radiologia|rayos x|ecografia(?:s)?|resonancia(?:s)?|tomografia(?:s)?)|solicitud (?:de )?(?:ayuda(?:s)? diagnostica(?:s)?|imagen(?:es)?(?: diagnostica(?:s)?| dx)?)(?: extramural(?:es)?)?)', 'Orden de imágenes diagnósticas',
            'Con ella puedes agendar el estudio de imágenes indicado (radiografía, ecografía, resonancia, etc.).'],
        'procedimiento' => ['(?:orden(?:es)? de (?:procedimiento(?:s)?|cirugia(?:s)?)|solicitud (?:de )?procedimiento(?:s)?(?: quirurgico(?:s)?)?(?: extramural(?:es)?)?)', 'Orden de procedimiento',
            'Presenta esta orden para programar el procedimiento indicado por tu especialista.'],
        'terapia' => ['(?:orden(?:es)? de (?:terapia(?:s)?|fisioterapia|rehabilitacion)|solicitud (?:de )?terapia(?:s)?(?: fisica(?:s)?)?(?: extramural(?:es)?)?)', 'Orden de terapias',
            'Preséntala para programar tus sesiones de terapia.'],
        'remision' => ['(?:orden(?:es)? de (?:remision|interconsulta)|remision a (?:especialista|especialidad)|solicitud (?:de )?interconsulta(?:s)?(?: extramural(?:es)?)?)', 'Remisión o interconsulta',
            'Úsala para solicitar tu cita con la especialidad indicada.'],
        'incapacidad' => ['(?:incapacidad(?: medica)?|certificado de incapacidad)', 'Incapacidad médica',
            'Entrégala en tu empresa o entidad cuando te la soliciten.'],
    ];

    /**
     * @param string[] $pageTexts texto por página (índice 0 = página 1)
     * @return array<int,array{type:string,page:int,details:array}>
     */
    public static function detect(array $pageTexts): array
    {
        $found = [];
        foreach (array_values($pageTexts) as $index => $text) {
            $normalized = strtolower(ClinicalPdfReader::normalize($text));
            foreach (self::TYPES as $type => $definition) {
                if (preg_match('/(?:^|\n)[ \t]*' . $definition[0] . '[ \t]*(?::|\n|$)/u', $normalized)) {
                    $found[] = ['type' => $type, 'page' => $index + 1, 'details' => self::details($type, $text)];
                }
            }
        }
        return $found;
    }

    /**
     * Lo que se ordenó, leído de la página de la orden («PLAN DE MANEJO» hasta «IMPRESIÓN DIAGNÓSTICA»):
     * items (estudios, exámenes, consultas o medicamentos con su indicación), when (p. ej. «1 mes y medio» para
     * pedir la cita) y validity (vigencia en días). Si el formato no se reconoce, devuelve listas vacías.
     * @return array{items:string[],when:?string,validity:?int}
     */
    public static function details(string $type, string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        // «Vigencia: 180» o, según cómo se extrae el texto, «180 Vigencia:».
        $validity = (preg_match('/vigencia\s*:?\s*(\d{1,3})\b/iu', $text, $m) || preg_match('/\b(\d{1,3})\s+vigencia\s*:/iu', $text, $m)) && (int)$m[1] > 0 ? (int)$m[1] : null;
        $start = preg_match('/plan\s+de\s+manejo/iu', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : 0;
        $section = substr($text, $start);
        if (preg_match('/impresi[oó]n\s+diagn[oó]stica/iu', $section, $m, PREG_OFFSET_CAPTURE)) $section = substr($section, 0, $m[0][1]);
        $lines = array_values(array_filter(array_map(fn($line) => trim(preg_replace('/ {2,}/', ' ', $line)), explode("\n", $section)), 'strlen'));
        $items = []; $when = null;
        $upper = '[A-ZÁÉÍÓÚÑÜ]';
        $nameChars = '[A-ZÁÉÍÓÚÑÜ0-9 ,()\/+%-]';
        if ($type === 'formula') {
            // «MEDICAMENTO 20 mg Presentación Administrar 20 mg cada 3 Dia(s) de forma ORAL por 90 Dia(s)» (puede seguir en la línea siguiente).
            foreach ($lines as $i => $line) {
                if (!preg_match('/^(' . $upper . '{4,}.*?)\s+Administrar\s+(.*)$/u', $line, $m)) continue;
                $dose = $m[2];
                if (!preg_match('/\bpor\s+\d+/iu', $dose) && isset($lines[$i + 1])) $dose .= ' ' . $lines[$i + 1];
                $dose = preg_replace('/\s+por\s+(\d+\s+D[ií]a\(s\)).*$/iu', ' por $1', $dose);
                $item = '*' . self::sentence($m[1]) . '*: ' . mb_strtolower(trim($dose));
                for ($j = $i + 1; $j <= $i + 3 && isset($lines[$j]); $j++) {
                    if (preg_match('/^Indicaciones\s*:\s*(.+)$/iu', $lines[$j], $ind)) { $item .= ' (' . mb_strtolower(trim($ind[1])) . ')'; break; }
                }
                $items[] = $item;
            }
        } else {
            $joined = implode(' ', $lines);
            // Código CUPS antes del nombre («901107 COLORACION GRAM…. 1») o después («ECOGRAFIA PELVICA…. 881401»).
            preg_match_all('/\b\d{6}\s+(' . $upper . $nameChars . '{4,}?)\.?(?=\s+\d{1,3}\b|\s+Datos|\s*$|\s+' . $upper . '{2,}\s*:)/u', $joined, $before);
            preg_match_all('/(' . $upper . $nameChars . '{5,}?)\.\s+\d{6,7}\b/u', $joined, $after);
            foreach (array_merge($before[1], $after[1]) as $name) {
                $name = trim(preg_replace('/^\d+\s*/', '', $name));
                // En las interconsultas la especialidad de la orden anterior queda pegada antes de «CONSULTA …».
                if ($type === 'remision' && preg_match('/^.+?\s(CONSULTA\s.+)$/u', $name, $cut)) $name = $cut[1];
                if (mb_strlen($name) >= 5 && !preg_match('/EXTRAMURAL|^C[OÓ]DIGO|^SERVICIO/u', $name)) $items[] = '*' . self::sentence($name) . '*';
            }
            foreach ($lines as $line) {
                if (preg_match('/^(\d+\s+(?:mes(?:es)?|semanas?|d[ií]as?|a[nñ]os?)\b.*)$/iu', $line, $m)) { $when = mb_strtolower($m[1]); break; }
            }
        }
        return ['items' => array_values(array_unique(array_slice($items, 0, 8))), 'when' => $when, 'validity' => $validity];
    }

    /** «ECOGRAFIA PELVICA» → «Ecografia pelvica» (los PDF vienen en mayúsculas). */
    private static function sentence(string $value): string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', $value), " .\t"));
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    public static function label(string $type): string { return self::TYPES[$type][1] ?? $type; }

    public static function guidance(string $type): string { return self::TYPES[$type][2] ?? ''; }
}
