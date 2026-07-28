<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;

/**
 * Zpětné zmrazení už vystavených faktur (doklady z doby před zavedením
 * snapshotů). Použije SOUČASNÁ data klienta/organizace — lepší okamžik
 * k dispozici není a od spuštění se údaje dál nezmění.
 *
 * Koncepty se přeskakují (mají zůstat živé) a existující snapshot se nikdy
 * nepřepisuje.
 */
class SnapshotInvoicesCommand extends Command
{
    protected $signature = 'ucetni-prehled:snapshot-invoices {--dry-run : Jen vypíše, co by se stalo}';

    protected $description = 'Doplní snapshot údajů k vystaveným fakturám, kde chybí';

    public function handle(OrganizationContext $context): int
    {
        $total = 0;

        foreach (Organization::withoutGlobalScopes()->get() as $organization) {
            $context->forceSet($organization);

            $invoices = Invoice::query()
                ->where('status', '!=', InvoiceStatus::Draft)
                ->whereNull('snapshot')
                ->with(['organization', 'client', 'bankAccount'])
                ->get();

            foreach ($invoices as $invoice) {
                if ($this->option('dry-run')) {
                    $this->line(sprintf('  ~ %s (%s)', $invoice->number ?? 'bez čísla', $invoice->client?->name ?? '—'));
                } else {
                    $invoice->takeSnapshot();
                    $invoice->save();
                }

                $total++;
            }
        }

        $this->info(sprintf('%s snapshot u %d faktur.',
            $this->option('dry-run') ? 'Doplnil by se' : 'Doplněn', $total));

        return self::SUCCESS;
    }
}
