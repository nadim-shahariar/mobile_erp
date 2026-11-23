<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** @internal Normalises nullable Carbon values for the numbering service. */
final class CarbonImmutableAdapter
{
    public static function from(?CarbonInterface $moment): CarbonImmutable
    {
        return $moment === null
            ? BusinessClock::now()
            : CarbonImmutable::instance($moment)->setTimezone(BusinessClock::timezone());
    }
}
