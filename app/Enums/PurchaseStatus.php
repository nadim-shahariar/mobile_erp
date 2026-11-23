<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseStatus: string
{
    case Draft = 'DRAFT';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
