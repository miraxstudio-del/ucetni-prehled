<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Vlastník',
            self::Member => 'Člen',
            self::Accountant => 'Účetní',
        };
    }

    /** Role smí zapisovat (faktury, klienti, banka). Účetní má jen čtení + exporty. */
    public function canWrite(): bool
    {
        return $this !== self::Accountant;
    }
}
