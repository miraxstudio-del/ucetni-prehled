<?php

namespace App\Services\Security;

use FurqanSiddiqui\BIP39\BIP39;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Převod mezi 12 slovy (BIP39) a šifrovacím klíčem.
 *
 * Proč slova místo řetězce `base64:…`:
 *  - dají se přepsat na papír bez překlepů (12. slovo je kontrolní součet,
 *    takže překlep se pozná hned, ne až při obnově)
 *  - jde je nadiktovat i přečíst nahlas
 *
 * 12 slov = 128 bitů entropie. Z nich se přes HKDF odvodí 256bitový klíč pro
 * AES-256. Bezpečnost odpovídá 128 bitům — což je i tak mimo dosah útoku
 * hrubou silou (2^128 možností).
 */
class MnemonicKey
{
    private const WORD_COUNT = 12;

    private const DERIVE_INFO = 'ucetni-prehled-master-key-v1';

    /**
     * @return array{words: array<int, string>, key: string} klíč = 32 bajtů
     */
    public function generate(): array
    {
        $mnemonic = BIP39::Generate(self::WORD_COUNT);

        return [
            'words' => $mnemonic->words,
            'key' => $this->deriveKey($mnemonic->entropy),
        ];
    }

    /** Odvodí klíč zpět ze slov (obnova ze zálohy). */
    public function toKey(#[SensitiveParameter] string $phrase): string
    {
        $normalized = $this->normalize($phrase);

        try {
            $mnemonic = BIP39::Words($normalized);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Neplatná fráze — zkontrolujte, že jde o '.self::WORD_COUNT
                .' slov ve správném pořadí a bez překlepů.',
                previous: $e,
            );
        }

        return $this->deriveKey($mnemonic->entropy);
    }

    public function isValidPhrase(#[SensitiveParameter] string $phrase): bool
    {
        try {
            $this->toKey($phrase);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Formát pro .env (base64:…). */
    public function toEnvValue(#[SensitiveParameter] string $key): string
    {
        return 'base64:'.base64_encode($key);
    }

    public function wordCount(): int
    {
        return self::WORD_COUNT;
    }

    /** 128 bitů entropie → 256bitový klíč pro AES-256 (HKDF, ne holý hash). */
    private function deriveKey(#[SensitiveParameter] string $entropyHex): string
    {
        $entropy = hex2bin($entropyHex);

        if ($entropy === false) {
            throw new RuntimeException('Poškozená entropie fráze.');
        }

        return hash_hkdf('sha256', $entropy, 32, self::DERIVE_INFO);
    }

    /** Sjednotí zápis: bere i čárky, nezáleží na velikosti písmen ani mezerách. */
    private function normalize(#[SensitiveParameter] string $phrase): string
    {
        $phrase = (string) preg_replace('/[,;\r\n\t]+/u', ' ', $phrase);
        $phrase = (string) preg_replace('/\s+/u', ' ', $phrase);

        return mb_strtolower(trim($phrase, " .\t\n\r"));
    }
}
