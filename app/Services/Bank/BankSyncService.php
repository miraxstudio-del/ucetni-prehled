<?php

namespace App\Services\Bank;

use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Services\Audit\AuditLogger;
use Throwable;

/**
 * Synchronizace transakcí z bankovního API: stažení, deduplikace,
 * uložení a automatické spárování plateb s fakturami.
 */
class BankSyncService
{
    /** @var array<string, BankConnector> */
    private array $connectors;

    public function __construct(
        FioConnector $fio,
        private readonly PaymentMatcher $matcher,
        private readonly AuditLogger $audit,
    ) {
        $this->connectors = [$fio->provider() => $fio];
    }

    /**
     * @return array{imported: int, matched: int}
     */
    public function sync(BankConnection $connection): array
    {
        $connector = $this->connectors[$connection->provider]
            ?? throw new \InvalidArgumentException("Neznámý konektor: {$connection->provider}");

        try {
            $fetched = $connector->fetchNewTransactions($connection);
        } catch (Throwable $e) {
            $connection->update([
                'status' => 'error',
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }

        $imported = 0;
        $matched = 0;

        foreach ($fetched as $data) {
            $transaction = $this->store($connection, $data, source: 'fio_api');

            if ($transaction === null) {
                continue; // duplicita — už ji máme
            }

            $imported++;

            if ($this->matcher->autoMatch($transaction)) {
                $matched++;
            }
        }

        $connection->update([
            'status' => 'active',
            'last_error' => null,
            'last_sync_at' => now(),
        ]);

        $this->audit->log('bank.synced', meta: [
            'connection_id' => $connection->id,
            'imported' => $imported,
            'matched' => $matched,
        ]);

        return ['imported' => $imported, 'matched' => $matched];
    }

    /** Uloží transakci; vrací null, pokud už existuje (dedup na external_id). */
    public function store(BankConnection|int $connectionOrAccountId, TransactionData $data, string $source): ?BankTransaction
    {
        $accountId = $connectionOrAccountId instanceof BankConnection
            ? $connectionOrAccountId->bank_account_id
            : $connectionOrAccountId;

        $exists = BankTransaction::query()
            ->where('bank_account_id', $accountId)
            ->where('external_id', $data->externalId)
            ->exists();

        if ($exists) {
            return null;
        }

        return BankTransaction::create([
            'bank_account_id' => $accountId,
            'external_id' => $data->externalId,
            'booked_on' => $data->bookedOn,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'counterparty_account' => $data->counterpartyAccount,
            'counterparty_name' => $data->counterpartyName,
            'variable_symbol' => $data->variableSymbol ?: null,
            'constant_symbol' => $data->constantSymbol ?: null,
            'specific_symbol' => $data->specificSymbol ?: null,
            'message' => $data->message ? mb_substr($data->message, 0, 255) : null,
            'import_source' => $source,
            'raw' => $data->raw ?: null,
        ]);
    }
}
