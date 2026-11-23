<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InsufficientStockException extends ErpException
{
    public static function accessory(string $variantLabel, int $requested, int $available): self
    {
        return new self(
            "Insufficient stock for {$variantLabel}: requested {$requested}, available {$available}.",
            Response::HTTP_CONFLICT,
            ['quantity' => "Only {$available} unit(s) available."],
        );
    }
}
