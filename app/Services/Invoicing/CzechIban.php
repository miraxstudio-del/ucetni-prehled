<?php

namespace App\Services\Invoicing;

/**
 * Výpočet českého IBAN z národního čísla účtu (předčíslí-číslo/kód banky).
 * Formát: CZkk BBBB PPPP PPNN NNNN NNNN (kód banky, předčíslí 6, číslo 10).
 */
class CzechIban
{
    public static function fromNational(?string $prefix, string $number, string $bankCode): string
    {
        $bban = str_pad($bankCode, 4, '0', STR_PAD_LEFT)
            .str_pad((string) $prefix, 6, '0', STR_PAD_LEFT)
            .str_pad($number, 10, '0', STR_PAD_LEFT);

        // kontrolní číslice dle ISO 13616 (mod 97-10)
        $numeric = $bban.'123500'; // CZ = 12 35, kontrola 00
        $checksum = 98 - (int) bcmod($numeric, '97');

        return 'CZ'.str_pad((string) $checksum, 2, '0', STR_PAD_LEFT).$bban;
    }

    /** Rozparsuje "123-1234567890/0100" i "1234567890/0100". */
    public static function parseNational(string $input): ?array
    {
        if (! preg_match('~^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$~', trim($input), $m)) {
            return null;
        }

        return [
            'prefix' => $m[1] !== '' ? $m[1] : null,
            'number' => $m[2],
            'bank_code' => $m[3],
        ];
    }
}
