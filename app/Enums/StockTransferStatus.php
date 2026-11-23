<?php

declare(strict_types=1);

namespace App\Enums;

enum StockTransferStatus: string
{
    case Requested = 'REQUESTED';
    case Dispatched = 'DISPATCHED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Dispatched], true);
    }
}
