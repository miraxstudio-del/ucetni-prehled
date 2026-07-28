<?php

namespace App\Enums;

enum InvoiceDirection: string
{
    case Issued = 'issued';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Vydaná',
            self::Received => 'Přijatá',
        };
    }
}
