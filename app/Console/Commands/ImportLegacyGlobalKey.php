<?php

namespace App\Console\Commands;

use App\Models\EncryptionKey;
use App\Models\User;
use App\Services\Security\DataCipher;
use App\Services\Security\KeyVault;
use Illuminate\Console\Command;
use Throwable;

/**
 * Přechod ze starého způsobu, kdy se odvozovaly z hlavního klíče:
 *   - globální klíč (šifruje uživatele a audit log)
 *   - klíč slepých indexů (podle nich se hledá e-mail při přihlášení)
 *
 * Odvozování bylo chybné: po výměně hlavního klíče se změnily i tyhle dva,
 * takže se uživatelé přestali dešifrovat a nešlo se přihlásit.
 *
 * Příkaz je odvodí z PŮVODNÍHO hlavního klíče a uloží zabalené AKTUÁLNÍM —
 * data i přihlášení tak zůstanou funkční.
 */
class ImportLegacyGlobalKey extends Command
{
    /** Jak se klíče odvozovaly dřív. */
    private const LEGACY_KEYS = [
        'global' => 'ucetni-prehled-global-data-v1',
        'blind-index' => 'ucetni-prehled-blind-index-v1',
    ];

    protected $signature = 'ucetni-prehled:import-legacy-global-key {--old-key= : Původní UCETNI_PREHLED_ENCRYPTION_KEY (base64:…)}
                                                          {--force : Přepsat i existující klíče}';

    protected $description = 'Převede dříve odvozované systémové klíče do tabulky (nutné po změně hlavního klíče)';

    public function handle(KeyVault $vault, DataCipher $cipher): int
    {
        $oldKeyInput = (string) ($this->option('old-key') ?: $this->secret('Původní UCETNI_PREHLED_ENCRYPTION_KEY (base64:…)'));

        $oldKek = str_starts_with($oldKeyInput, 'base64:')
            ? base64_decode(substr($oldKeyInput, 7), true)
            : $oldKeyInput;

        if ($oldKek === false || strlen($oldKek) !== 32) {
            $this->error('Původní klíč musí být 32 bajtů (base64:…).');

            return self::FAILURE;
        }

        foreach (self::LEGACY_KEYS as $name => $info) {
            $existing = EncryptionKey::where('name', $name)->first();

            if ($existing && ! $this->option('force')) {
                $this->warn("Klíč '{$name}' už v tabulce je — přeskočeno (přepis: --force).");

                continue;
            }

            $legacyKey = hash_hkdf('sha256', $oldKek, 32, $info);

            EncryptionKey::updateOrCreate(['name' => $name], [
                'wrapped_key' => $cipher->encrypt($legacyKey, $vault->masterKey(), 'organization.data_key'),
            ]);

            $this->line("  převeden klíč: {$name}");
        }

        $vault->forget();

        // Kontrola: uživatel se musí dát dešifrovat I dohledat přes slepý index
        try {
            $user = User::first();

            if ($user !== null) {
                if (blank($user->email)) {
                    throw new \RuntimeException('Uživatele se nedaří dešifrovat.');
                }

                if (User::whereEmail($user->email)->first() === null) {
                    throw new \RuntimeException('Uživatel se nedohledá přes slepý index — přihlášení by nefungovalo.');
                }
            }
        } catch (Throwable $e) {
            $this->error('Ověření selhalo: '.$e->getMessage());
            $this->warn('Zkontrolujte, že --old-key je opravdu ten, kterým byla data zašifrována.');

            return self::FAILURE;
        }

        $this->info('Hotovo — systémové klíče jsou v tabulce encryption_keys.');
        $this->line('Kontrola: přihlášení najde uživatele — '.(User::first()?->email ?? 'žádný uživatel'));

        return self::SUCCESS;
    }
}
