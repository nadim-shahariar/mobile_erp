<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerType: string
{
    case Person = 'PERSON';
    case Shop = 'SHOP';

    public function label(): string
    {
        return $this === self::Shop ? 'Shop / wholesale' : 'Person';
    }
}
