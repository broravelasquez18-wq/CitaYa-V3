<?php
declare(strict_types=1);
namespace App;

/**
 * PDF de historia al que le faltan datos del paciente o de la atención. Lleva qué falta y lo que sí se leyó,
 * para que una persona complete a mano solo lo que falta (ClinicalPdfReader::FIELDS).
 */
final class ClinicalPdfMissingData extends ClinicalPdfException
{
    /** @param string[] $missing campos de ClinicalPdfReader::FIELDS  @param array<string,string> $found */
    public function __construct(public readonly array $missing, public readonly array $found, string $where)
    {
        $labels = array_map(fn($field) => ClinicalPdfReader::FIELDS[$field], $missing);
        parent::__construct('No se encontró ' . self::join($labels) . ' ' . $where . '. Puedes escribirlo a mano para cargar este PDF.');
    }

    private static function join(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' ni ' . end($items) : (string)reset($items);
    }
}
