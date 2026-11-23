<?php

declare(strict_types=1);

namespace App\Enums;

enum SaleType: string
{
    case Retail = 'RETAIL';
    case Shop = 'SHOP';

    public function label(): string
    {
        return $this === self::Shop ? 'Shop / wholesale' : 'Retail';
    }
}
