<?php
declare(strict_types=1);
namespace App;

final class WhatsAppTransportError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retry) { parent::__construct($message); }
}
