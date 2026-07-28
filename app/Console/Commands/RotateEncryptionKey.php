<?php

namespace App\Console\Commands;

use App\Models\EncryptionKey;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\KeyVault;
use App\Services\Security\MnemonicKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Výměna hlavního klíče (KEK).
 *
 * Díky obálkovému šifrování se NEPŘEŠIFROVÁVAJÍ data — jen se přebalí datové
 * klíče (organizations.data_key a encryption_keys), kterých je pár. Proto je
 * rotace levná a bezpečná: buď projde celá, nebo se nic nezmění (transakce).
 *
 * Použití: při podezření na únik klíče, nebo pro převedení starého náhodného
 * klíče na klíč zálohovatelný 12 slovy.
 */
class RotateEncryptionKey extends Command
{
    protected $signature = 'ucetni-prehled:key-rotate {--words= : Nová fráze (jinak se vygeneruje)}
                                            {--force : Nezeptat se na potvrzení}';

    protected $description = 'Vymění hlavní šifrovací klíč (přebalí datové klíče, data zůstanou)';

    public function handle(MnemonicKey $mnemonic, KeyVault $vault): int
    {
        $oldKey = $vault->masterKey();

        // 1) Ověřit, že starým klíčem opravdu jde data odemknout
        try {
            $this->verifyCurrentKey($vault);
        } catch (Throwable $e) {
            $this->error('Stávající klíč nesedí k datům: '.$e->getMessage());
            $this->warn('Rotace by data znepřístupnila — přerušeno.');

            return self::FAILURE;
        }

        // 2) Nový klíč
        if ($words = $this->option('words')) {
            try {
                $newKey = $mnemonic->toKey($words);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $newWords = explode(' ', trim(preg_replace('/\s+/', ' ', $words)));
        } else {
            $generated = $mnemonic->generate();
            $newKey = $generated['key'];
            $newWords = $generated['words'];
        }

        $organizations = Organization::withoutGlobalScopes()->withTrashed()->count();
        $systemKeys = EncryptionKey::count();

        $this->info("Přebalí se {$organizations} klíčů organizací a {$systemKeys} systémových klíčů.");
        $this->line('Data se nepřešifrovávají — zůstanou beze změny.');

        if (! $this->option('force') && ! $this->confirm('Pokračovat?', true)) {
            return self::FAILURE;
        }

        // 3) Přebalení v transakci — buď vše, nebo nic
        try {
            DB::transaction(function () use ($vault, $oldKey, $newKey) {
                Organization::withoutGlobalScopes()->withTrashed()->each(function (Organization $organization) use ($vault, $oldKey, $newKey) {
                    $wrapped = $organization->getAttributeValue('data_key');

                    if (blank($wrapped)) {
                        return;
                    }

                    $organization->forceFill([
                        'data_key' => $vault->rewrapDataKey($wrapped, $oldKey, $newKey),
                    ])->saveQuietly();
                });

                EncryptionKey::each(function (EncryptionKey $record) use ($vault, $oldKey, $newKey) {
                    $record->forceFill([
                        'wrapped_key' => $vault->rewrapDataKey($record->wrapped_key, $oldKey, $newKey),
                    ])->save();
                });
            });
        } catch (Throwable $e) {
            $this->error('Rotace selhala, nic se nezměnilo: '.$e->getMessage());

            return self::FAILURE;
        }

        // 4) Zapsat nový klíč do .env
        //    (v testech nikdy — jinak by běh testů přepsal skutečný klíč
        //    vývojáře a znepřístupnil mu lokální data)
        $envValue = $mnemonic->toEnvValue($newKey);
        // POZOR: ne base_path('.env') — při --env=production musí zápis jít
        // do .env.production, jinak by nový klíč skončil v souboru pro jiné
        // prostředí a data by se stala nečitelnými.
        $path = app()->environmentFilePath();

        if (app()->environment('testing')) {
            $this->line('Testovací režim — .env se nezapisuje.');
        } elseif (File::exists($path)) {
            $contents = preg_replace(
                '/^UCETNI_PREHLED_ENCRYPTION_KEY=.*$/m',
                'UCETNI_PREHLED_ENCRYPTION_KEY='.$envValue,
                File::get($path),
            );
            File::put($path, $contents);
            $this->info('Nový klíč zapsán do '.basename($path).'.');
        } else {
            $this->warn(basename($path).' nenalezen — vložte ručně: UCETNI_PREHLED_ENCRYPTION_KEY='.$envValue);
        }

        $vault->forget();

        $this->newLine();
        $this->warn('  NOVÁ ZÁLOŽNÍ FRÁZE (stará už neplatí!):');
        $this->newLine();

        foreach (array_chunk($newWords, 4) as $row => $chunk) {
            $line = '';
            foreach ($chunk as $i => $word) {
                $line .= sprintf('  %2d. %-10s', $row * 4 + $i + 1, $word);
            }
            $this->line($line);
        }

        $this->newLine();
        $this->info('Hotovo. Na ostatních serverech nezapomeňte .env aktualizovat.');

        return self::SUCCESS;
    }

    /**
     * Pojistka: než něco přepíšeme, musí stávající klíč data odemknout.
     *
     * Zároveň vynutí existenci globálního klíče v tabulce — jinak by se
     * rotace provedla „naprázdno" a data uživatelů (šifrovaná globálním
     * klíčem) by se po výměně už nedešifrovala.
     */
    private function verifyCurrentKey(KeyVault $vault): void
    {
        $organization = Organization::withoutGlobalScopes()->withTrashed()->first();

        if ($organization !== null) {
            $vault->dataKeyFor($organization);
        }

        // Systémové klíče musí být v tabulce, jinak by je rotace minula a
        // data uživatelů (globální klíč) i přihlášení (slepé indexy) by
        // po výměně přestaly fungovat.
        if (User::exists()) {
            foreach (['global', 'blind-index'] as $name) {
                if (! EncryptionKey::where('name', $name)->exists()) {
                    throw new \RuntimeException(
                        "Systémový klíč '{$name}' není v tabulce encryption_keys, ale uživatelé už existují. "
                        .'Nejdřív spusťte: php artisan ucetni-prehled:import-legacy-global-key'
                    );
                }
            }
        }

        $vault->globalDataKey();
        $vault->blindIndexKey();
    }
}
