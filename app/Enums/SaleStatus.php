<?php

declare(strict_types=1);

namespace App\Enums;

enum SaleStatus: string
{
    case Posted = 'POSTED';
    case Voided = 'VOIDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }
}
