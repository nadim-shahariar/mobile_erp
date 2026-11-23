<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductType: string
{
    case Phone = 'PHONE';
    case Accessory = 'ACCESSORY';

    public function label(): string
    {
        return $this === self::Phone ? 'Phone' : 'Accessory';
    }
}
