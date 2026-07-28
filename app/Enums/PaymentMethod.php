<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Převodem',
            self::Cash => 'Hotově',
            self::Card => 'Kartou',
        };
    }
}
