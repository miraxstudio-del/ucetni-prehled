<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bank\PaymentVerifier;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;

/**
 * Ověření stavů „zaplaceno“ proti bance.
 *
 * Import z PDF označil faktury jako zaplacené paušálně (uzavřené roky) —
 * u běžícího roku je to ale jen domněnka. Tenhle příkaz ji nahradí důkazem:
 *
 *  1. faktury označené importem (Paid bez data úhrady a bez spárované
 *     platby) v běžícím roce vrátí na „vystaveno“;
 *  2. zkusí je spárovat s příchozími platbami (viz PaymentVerifier);
 *  3. co důkaz o platbě nemá, zůstane nezaplacené a vypíše se.
 *
 * Uzavřené roky (validované přiznáním) se nechávají zaplacené — platby šly
 * z velké části mimo tento účet (dobírky, agregované výplaty Aukra).
 *
 * Jádro hledání platby je v PaymentVerifier — sdílené i s ručním spuštěním
 * z UI (BankController::verifyPayments).
 */
class VerifyPaymentsCommand extends Command
{
    protected $signature = 'ucetni-prehled:verify-payments
        {--year= : Rok (výchozí běžící)}
        {--organization= : ID organizace (výchozí všechny)}
        {--dry-run : Jen vypíše, co by se stalo}';

    protected $description = 'Ověří stavy zaplacení faktur proti bankovním transakcím';

    public function handle(OrganizationContext $context, PaymentVerifier $verifier): int
    {
        $year = $this->option('year') ? (int) $this->option('year') : now()->year;
        $dry = (bool) $this->option('dry-run');

        $organizations = Organization::withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($organizations as $organization) {
            $context->forceSet($organization);
            $this->info("Organizace: {$organization->name} (#{$organization->id}), rok {$year}");

            $result = $verifier->verify($year, $dry);

            foreach ($result['paired'] as $number) {
                $this->line("  ✓ {$number}  spárováno s bankou");
            }

            foreach ($result['downgraded'] as $number) {
                $this->line(sprintf('  ✗ %s  bez dokladu o platbě → %s', $number,
                    $dry ? 'vrátilo by se na „vystaveno“' : 'vráceno na „vystaveno“'));
            }

            $this->info(sprintf('  spárováno s bankou: %d | %s na nezaplacené: %d',
                count($result['paired']), $dry ? 'vrátilo by se' : 'vráceno', count($result['downgraded'])));

            if ($result['closed_year'] && $result['downgraded'] === []) {
                $this->line('  (uzavřený rok — neověřené faktury zůstávají zaplacené dle přiznání)');
            }

            if ($result['unpaid'] !== []) {
                $this->warn('  Bez dokladu o platbě: '.implode(', ', $result['unpaid']));
            }
        }

        return self::SUCCESS;
    }
}
