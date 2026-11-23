<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Custody;
use App\Enums\Sellability;
use Symfony\Component\HttpFoundation\Response;

class InvalidStockTransitionException extends ErpException
{
    public static function custody(Custody $from, Custody $to): self
    {
        return new self(
            "Cannot move a handset from [{$from->label()}] to [{$to->label()}].",
            Response::HTTP_CONFLICT,
            ['state' => "Invalid custody transition {$from->value} -> {$to->value}."],
        );
    }

    public static function sellability(Sellability $from, Sellability $to): self
    {
        return new self(
            "Cannot change sellability from [{$from->label()}] to [{$to->label()}].",
            Response::HTTP_CONFLICT,
            ['state' => "Invalid sellability transition {$from->value} -> {$to->value}."],
        );
    }

    public static function unitRequired(Custody $custody): self
    {
        return new self(
            'A destination unit is required to place this handset in a unit.',
            Response::HTTP_CONFLICT,
            ['unit_id' => 'Destination unit is required.'],
        );
    }

    public static function unitNotAllowed(Custody $custody): self
    {
        return new self(
            "A handset in custody [{$custody->label()}] cannot be assigned to a unit.",
            Response::HTTP_CONFLICT,
            ['unit_id' => 'No unit may be set for this custody state.'],
        );
    }

    public static function unitMismatch(int $expected, ?int $actual): self
    {
        return new self(
            "Handset is not physically in the expected unit (expected {$expected}, found ".($actual ?? 'none').').',
            Response::HTTP_CONFLICT,
            ['unit_id' => 'The handset is in another unit or in transit.'],
        );
    }
}
