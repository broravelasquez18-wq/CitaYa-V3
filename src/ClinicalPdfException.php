<?php
declare(strict_types=1);
namespace App;

/** PDF de historia que no se pudo leer: el motivo y, cuando se conoce, la página donde está el problema. */
class ClinicalPdfException extends \DomainException
{
    public function __construct(string $reason, public readonly ?int $page = null)
    {
        parent::__construct($page ? 'Página ' . $page . ': ' . $reason : $reason);
    }

    /** Motivo sin el prefijo de la página, para mostrar la página aparte. */
    public function reason(): string
    {
        return $this->page ? substr($this->getMessage(), strlen('Página ' . $this->page . ': ')) : $this->getMessage();
    }
}
