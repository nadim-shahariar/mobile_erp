<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class UnitAccessDeniedException extends ErpException
{
    public static function forUnit(int|string|null $unitId): self
    {
        return new self(
            'You do not have access to the requested business unit.',
            Response::HTTP_FORBIDDEN,
            ['unit_id' => 'Unit access denied'.($unitId !== null ? " for unit {$unitId}" : '').'.'],
        );
    }
}
