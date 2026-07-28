<?php

namespace App\Services\Security;

/**
 * Slepý index — HMAC otisk hodnoty, podle kterého lze v SQL hledat přesnou
 * shodu, aniž by v databázi byla čitelná hodnota.
 *
 * Např. přihlášení: users.email je zašifrovaný, ale users.email_index
 * = HMAC(e-mail) umožní najít uživatele jedním dotazem.
 *
 * Vědomé omezení: stejná hodnota dá stejný otisk, takže útočník s databází
 * pozná, že dva klienti mají shodný e-mail, a může zkoušet, zda konkrétní
 * hodnotu zná (bez klíče ji ale odvodit nedokáže). Proto slepé indexy
 * děláme jen tam, kde je vyhledávání potřeba.
 */
class BlindIndex
{
    public function __construct(
        private readonly KeyVault $vault,
    ) {}

    /** Otisk pro přesnou shodu; null pro prázdnou hodnotu. */
    public function make(?string $value, string $context): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        return hash_hmac('sha256', $context.'|'.$normalized, $this->vault->blindIndexKey());
    }

    /** Sjednocení zápisu, ať „Novák " a „novák" dají stejný otisk. */
    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_strtolower($value);
    }
}
