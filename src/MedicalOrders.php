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
        'formula' => ['(?:formula(?:s)? medica(?:s)?|formulacion medica|prescripcion medica|formula de medicamentos)', 'Fórmula médica',
            'Con esta fórmula puedes reclamar tus medicamentos en tu farmacia. Preséntala impresa o desde la imagen que te enviamos.'],
        'laboratorio' => ['(?:orden(?:es)? de laboratorio(?: clinico)?|orden(?:es)? de examen(?:es)?(?: de laboratorio)?)', 'Orden de laboratorio',
            'Preséntala en el laboratorio para realizarte los exámenes indicados.'],
        'imagenes' => ['(?:orden(?:es)? de (?:imagen(?:es)?(?: diagnostica(?:s)?)?|radiologia|rayos x|ecografia(?:s)?|resonancia(?:s)?|tomografia(?:s)?))', 'Orden de imágenes diagnósticas',
            'Con ella puedes agendar el estudio de imágenes indicado (radiografía, ecografía, resonancia, etc.).'],
        'procedimiento' => ['(?:orden(?:es)? de (?:procedimiento(?:s)?|cirugia(?:s)?))', 'Orden de procedimiento',
            'Presenta esta orden para programar el procedimiento indicado por tu especialista.'],
        'terapia' => ['(?:orden(?:es)? de (?:terapia(?:s)?|fisioterapia|rehabilitacion))', 'Orden de terapias',
            'Preséntala para programar tus sesiones de terapia.'],
        'remision' => ['(?:orden(?:es)? de (?:remision|interconsulta)|remision a (?:especialista|especialidad)|solicitud de interconsulta)', 'Remisión o interconsulta',
            'Úsala para solicitar tu cita con la especialidad indicada.'],
        'incapacidad' => ['(?:incapacidad(?: medica)?|certificado de incapacidad)', 'Incapacidad médica',
            'Entrégala en tu empresa o entidad cuando te la soliciten.'],
    ];

    /**
     * @param string[] $pageTexts texto por página (índice 0 = página 1)
     * @return array<int,array{type:string,page:int}>
     */
    public static function detect(array $pageTexts): array
    {
        $found = [];
        foreach (array_values($pageTexts) as $index => $text) {
            $normalized = strtolower(ClinicalPdfReader::normalize($text));
            foreach (self::TYPES as $type => $definition) {
                if (preg_match('/(?:^|\n)[ \t]*' . $definition[0] . '[ \t]*(?::|\n|$)/u', $normalized)) {
                    $found[] = ['type' => $type, 'page' => $index + 1];
                }
            }
        }
        return $found;
    }

    public static function label(string $type): string { return self::TYPES[$type][1] ?? $type; }

    public static function guidance(string $type): string { return self::TYPES[$type][2] ?? ''; }
}
