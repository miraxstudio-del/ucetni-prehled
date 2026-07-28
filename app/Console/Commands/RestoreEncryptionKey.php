<?php

namespace App\Console\Commands;

use App\Services\Security\MnemonicKey;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Odvodí hlavní šifrovací klíč zpět ze záložní fráze (12 slov).
 * Použití při obnově serveru ze zálohy, kde chybí .env.
 */
class RestoreEncryptionKey extends Command
{
    protected $signature = 'ucetni-prehled:key-restore {--words= : 12 slov v uvozovkách (jinak se zeptá)}';

    protected $description = 'Odvodí hlavní šifrovací klíč ze záložní fráze (12 slov)';

    public function handle(MnemonicKey $mnemonic): int
    {
        $phrase = (string) ($this->option('words') ?: $this->secret('Zadejte záložní frázi (12 slov oddělených mezerou)'));

        try {
            $key = $mnemonic->toKey($phrase);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $envValue = $mnemonic->toEnvValue($key);

        $this->info('Fráze je platná. Do .env na serveru vložte tento řádek:');
        $this->newLine();
        $this->line('  UCETNI_PREHLED_ENCRYPTION_KEY='.$envValue);
        $this->newLine();
        $this->warn('Musí jít o stejnou frázi, jakou byla data zašifrována — jinak se nedešifrují.');

        return self::SUCCESS;
    }
}
