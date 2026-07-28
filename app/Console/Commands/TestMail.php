<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ověření, že SMTP funguje: php artisan mail:test adresa@example.com
 * (na produkci: php artisan mail:test adresa@example.com --env=production)
 */
class TestMail extends Command
{
    protected $signature = 'mail:test {to : E-mail příjemce}';

    protected $description = 'Odešle testovací e-mail pro ověření SMTP nastavení';

    public function handle(): int
    {
        $to = $this->argument('to');

        $this->info('Mailer:  '.config('mail.default'));
        $this->info('Host:    '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->info('Od:      '.config('mail.from.address'));
        $this->info('Komu:    '.$to);
        $this->newLine();

        try {
            Mail::raw(
                "Toto je testovací zpráva z fakturačního systému Účetní přehled.\n\n"
                ."Pokud vám dorazila, odesílání e-mailů funguje správně —\n"
                ."ověřovací odkazy, pozvánky i faktury se budou odesílat.\n\n"
                .'Odesláno: '.now()->format('j. n. Y H:i:s'),
                fn ($message) => $message->to($to)->subject('Účetní přehled — test odesílání e-mailů'),
            );
        } catch (Throwable $e) {
            $this->error('SELHALO: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('OK — e-mail byl předán SMTP serveru.');

        return self::SUCCESS;
    }
}
