<?php

declare(strict_types=1);

namespace App\Enums;

enum LineType: string
{
    case Phone = 'PHONE';
    case Accessory = 'ACCESSORY';
}
