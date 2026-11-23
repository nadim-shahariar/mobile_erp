<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
    case Card = 'CARD';
    case Bkash = 'BKASH';
    case Nagad = 'NAGAD';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Other => 'Other',
        };
    }

    /** Payment accounts use the account type to drive cash/bank/MFS reporting. */
    public function accountType(): string
    {
        return match ($this) {
            self::Cash => 'CASH',
            self::BankTransfer => 'BANK',
            self::Card => 'CARD',
            self::Bkash, self::Nagad => 'MFS',
            self::Other => 'OTHER',
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}
