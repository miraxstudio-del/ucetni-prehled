<?php

namespace App\Console\Commands;

use App\Services\Security\MnemonicKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Vygeneruje hlavní šifrovací klíč (KEK) a zapíše ho do .env.
 * Klíč se zároveň vypíše jako 12 slov — ta se dají zapsat na papír bez
 * překlepů (poslední slovo je kontrolní součet) a klíč z nich lze kdykoli
 * odvodit zpět příkazem ucetni-prehled:key-restore.
 */
class GenerateEncryptionKey extends Command
{
    protected $signature = 'ucetni-prehled:key-generate {--show : Jen vypsat, nezapisovat do .env}';

    protected $description = 'Vygeneruje hlavní šifrovací klíč dat + zálohovací frázi (12 slov)';

    public function handle(MnemonicKey $mnemonic): int
    {
        $generated = $mnemonic->generate();
        $envValue = $mnemonic->toEnvValue($generated['key']);

        if ($this->option('show')) {
            $this->line($envValue);
            $this->newLine();
            $this->line(implode(' ', $generated['words']));

            return self::SUCCESS;
        }

        // ne base_path('.env') — při --env=production musí zápis jít
        // do .env.production, ne do souboru jiného prostředí
        $path = app()->environmentFilePath();

        if (! File::exists($path)) {
            $this->error('Soubor '.basename($path).' neexistuje.');

            return self::FAILURE;
        }

        if (trim((string) config('ucetni_prehled.encryption_key')) !== '') {
            $this->error('UCETNI_PREHLED_ENCRYPTION_KEY už je nastavený.');
            $this->warn('Přepsáním byste NENÁVRATNĚ ztratil přístup ke všem zašifrovaným datům.');
            $this->line('Výměna klíče: php artisan ucetni-prehled:key-rotate');

            return self::FAILURE;
        }

        $contents = File::get($path);
        $contents = preg_match('/^UCETNI_PREHLED_ENCRYPTION_KEY=.*$/m', $contents)
            ? preg_replace('/^UCETNI_PREHLED_ENCRYPTION_KEY=.*$/m', 'UCETNI_PREHLED_ENCRYPTION_KEY='.$envValue, $contents)
            : rtrim($contents)."\n\nUCETNI_PREHLED_ENCRYPTION_KEY=".$envValue."\n";

        File::put($path, $contents);

        $this->info('Šifrovací klíč byl vygenerován a zapsán do .env.');
        $this->newLine();
        $this->printPhrase($generated['words']);

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $words */
    private function printPhrase(array $words): void
    {
        $this->warn('  ZÁLOŽNÍ FRÁZE — zapište si ji na papír a uložte mimo počítač:');
        $this->newLine();

        foreach (array_chunk($words, 4) as $row => $chunk) {
            $line = '';
            foreach ($chunk as $i => $word) {
                $line .= sprintf('  %2d. %-10s', $row * 4 + $i + 1, $word);
            }
            $this->line($line);
        }

        $this->newLine();
        $this->warn('  Kdo frázi zná, dostane se ke všem datům. Kdo ji ztratí, nedostane se k nim NIKDY.');
        $this->line('  Obnova klíče z fráze: php artisan ucetni-prehled:key-restore');
    }
}
