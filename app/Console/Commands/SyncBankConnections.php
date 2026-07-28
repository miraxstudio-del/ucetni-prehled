<?php

namespace App\Console\Commands;

use App\Models\BankConnection;
use App\Services\Bank\BankSyncService;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hodinová synchronizace všech napojených bankovních účtů (spouští scheduler).
 */
class SyncBankConnections extends Command
{
    protected $signature = 'bank:sync';

    protected $description = 'Stáhne nové transakce ze všech napojených bankovních API';

    public function handle(BankSyncService $service, OrganizationContext $context): int
    {
        $connections = BankConnection::acrossAllOrganizations()
            ->with('organization')
            ->get();

        foreach ($connections as $connection) {
            $context->forceSet($connection->organization);

            try {
                $result = $service->sync($connection);
                $this->info("Spojení #{$connection->id}: nových {$result['imported']}, spárováno {$result['matched']}.");
            } catch (\Throwable $e) {
                Log::warning('Synchronizace banky selhala: '.$e->getMessage(), [
                    'connection_id' => $connection->id,
                ]);
                $this->error("Spojení #{$connection->id}: {$e->getMessage()}");
            }
        }

        $context->clear();

        return self::SUCCESS;
    }
}
