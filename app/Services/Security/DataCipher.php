<?php

namespace App\Services\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Nízkoúrovňová symetrická šifra pro data v databázi.
 *
 * AES-256-GCM (AEAD) — kromě utajení zajišťuje i integritu: útočník s přístupem
 * do DB nemůže ciphertext podvrhnout ani zaměnit mezi řádky, protože do
 * autentizovaných dat (AAD) vážeme kontext (tabulka, sloupec, ID řádku).
 *
 * Formát uloženého řetězce:  v1.<base64(nonce|tag|ciphertext)>
 */
class DataCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const VERSION = 'v1';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public function encrypt(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $key, string $context = ''): string
    {
        $this->assertKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Šifrování selhalo.');
        }

        return self::VERSION.'.'.base64_encode($nonce.$tag.$ciphertext);
    }

    public function decrypt(string $payload, #[SensitiveParameter] string $key, string $context = ''): string
    {
        $this->assertKey($key);

        [$version, $encoded] = array_pad(explode('.', $payload, 2), 2, null);

        if ($version !== self::VERSION || $encoded === null) {
            throw new RuntimeException('Neznámý formát šifrovaných dat.');
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Poškozená šifrovaná data.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,
        );

        // false = neplatný tag → data byla změněna, nebo je klíč špatný
        if ($plaintext === false) {
            throw new RuntimeException('Dešifrování selhalo — neplatný klíč nebo porušená data.');
        }

        return $plaintext;
    }

    /** Rozpozná, zda hodnota už je náš ciphertext (kvůli migraci a idempotenci). */
    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::VERSION.'.');
    }

    private function assertKey(#[SensitiveParameter] string $key): void
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Šifrovací klíč musí mít 32 bajtů (AES-256).');
        }
    }
}
