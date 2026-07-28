<?php

namespace App\Services\Security;

use App\Models\EncryptionKey;
use App\Models\Organization;
use RuntimeException;
use SensitiveParameter;

/**
 * Obálkové šifrování (envelope encryption).
 *
 *   UCETNI_PREHLED_ENCRYPTION_KEY (.env, mimo databázi)   = hlavní klíč (KEK)
 *        └─ šifruje ─> organizations.data_key   = datový klíč organizace (DEK)
 *                └─ šifruje ─> citlivá pole v databázi
 *
 * Proč tak: kdo získá jen databázi, má DEK pouze v zašifrované podobě a bez
 * KEK ze souborového systému je mu k ničemu. Zároveň jde DEK per organizace
 * zahodit (crypto-shredding při smazání) a KEK rotovat bez přešifrování dat.
 *
 * Klíč patří ORGANIZACI, ne uživateli — data sdílí vlastník, člen i účetní.
 */
class KeyVault
{
    /** Dešifrované DEK v paměti requestu (ať nedešifrujeme u každého pole znovu). */
    private array $cache = [];

    public function __construct(
        private readonly DataCipher $cipher,
    ) {}

    /** Vygeneruje nový náhodný datový klíč zašifrovaný hlavním klíčem. */
    public function generateWrappedDataKey(): string
    {
        return $this->cipher->encrypt(random_bytes(32), $this->masterKey(), 'organization.data_key');
    }

    /** Vrátí dešifrovaný datový klíč organizace (a doplní ho, pokud chybí). */
    public function dataKeyFor(Organization $organization): string
    {
        // ještě neuložená organizace nemá ID — cachujeme podle instance
        $cacheKey = $organization->getKey() !== null
            ? 'org:'.$organization->getKey()
            : 'new:'.spl_object_id($organization);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $wrapped = $organization->getAttributeValue('data_key');

        // Sloupec nemusel být načtený (např. with('organization:id,name')).
        // Bez tohoto dotažení bychom ho níže považovali za chybějící,
        // vygenerovali nový klíč a NENÁVRATNĚ znepřístupnili data.
        if (blank($wrapped) && $organization->exists) {
            $wrapped = Organization::withoutGlobalScopes()
                ->whereKey($organization->getKey())
                ->value('data_key');
        }

        if (blank($wrapped)) {
            // Nová organizace (klíč musí vzniknout dřív, než se zašifruje první
            // pole) nebo organizace založená před zavedením šifrování.
            $wrapped = $this->generateWrappedDataKey();
            $organization->forceFill(['data_key' => $wrapped]);

            if ($organization->exists) {
                $organization->saveQuietly();
            }
        }

        return $this->cache[$cacheKey] = $this->cipher->decrypt(
            $wrapped,
            $this->masterKey(),
            'organization.data_key',
        );
    }

    /**
     * Klíč pro záznamy, které nepatří žádné organizaci (uživatelé, audit log
     * neúspěšných přihlášení).
     *
     * Je uložený v DB zabalený hlavním klíčem (ne odvozený z něj), aby šlo
     * hlavní klíč vyměnit bez přešifrování dat — při rotaci se jen přebalí.
     */
    public function globalDataKey(): string
    {
        return $this->storedKey('global');
    }

    /** Systémový klíč z tabulky — zabalený hlavním klíčem, ne odvozený z něj. */
    private function storedKey(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $record = EncryptionKey::firstOrCreate(
            ['name' => $name],
            ['wrapped_key' => $this->generateWrappedDataKey()],
        );

        return $this->cache[$name] = $this->cipher->decrypt(
            $record->wrapped_key,
            $this->masterKey(),
            'organization.data_key',
        );
    }

    /**
     * Klíč pro slepé indexy (HMAC).
     *
     * Stejně jako globální klíč je uložený v DB zabalený, NE odvozený z
     * hlavního klíče. Odvozování bylo chybné: po výměně hlavního klíče by se
     * změnil i tenhle, přestaly by sedět uložené otisky a nešlo by se
     * přihlásit (e-mail se dohledává právě přes ně).
     */
    public function blindIndexKey(): string
    {
        return $this->storedKey('blind-index');
    }

    /** Přebalí datový klíč pod nový hlavní klíč (rotace KEK). */
    public function rewrapDataKey(string $wrapped, #[SensitiveParameter] string $oldMasterKey, #[SensitiveParameter] string $newMasterKey): string
    {
        $dek = $this->cipher->decrypt($wrapped, $oldMasterKey, 'organization.data_key');

        return $this->cipher->encrypt($dek, $newMasterKey, 'organization.data_key');
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** Hlavní klíč z .env — nikdy není v databázi. */
    public function masterKey(): string
    {
        $configured = (string) config('ucetni_prehled.encryption_key');

        if ($configured === '') {
            throw new RuntimeException(
                'Chybí UCETNI_PREHLED_ENCRYPTION_KEY v .env. Vygenerujete jej příkazem: php artisan ucetni-prehled:key-generate'
            );
        }

        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('UCETNI_PREHLED_ENCRYPTION_KEY musí být 32 bajtů (base64:...). Vygenerujte: php artisan ucetni-prehled:key-generate');
        }

        return $key;
    }
}
