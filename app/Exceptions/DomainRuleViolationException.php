<?php

declare(strict_types=1);

namespace App\Exceptions;

class DomainRuleViolationException extends ErpException
{
    public static function message(string $message, array $fieldErrors = []): self
    {
        return new self($message, 422, $fieldErrors);
    }
}
