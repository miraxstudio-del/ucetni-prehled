<?php

namespace App\Services\Bank;

use App\Models\BankConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fio banka — read-only REST API s osobním tokenem.
 * Dokumentace: https://www.fio.cz/bank-services/internetbanking-api
 *
 * Používá endpoint /periods/{token}/{od}/{do}/transactions.json s přesahem
 * několika dní; duplicitám brání unikátní ID pohybu (column22).
 */
class FioConnector implements BankConnector
{
    /** Fio omezuje dotazy na 1 za 30 s — při 409 je potřeba počkat. */
    private const OVERLAP_DAYS = 5;

    public function provider(): string
    {
        return 'fio';
    }

    public function fetchNewTransactions(BankConnection $connection): array
    {
        $from = ($connection->last_sync_at?->subDays(self::OVERLAP_DAYS) ?? now()->subDays(90))
            ->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $url = sprintf(
            '%s/periods/%s/%s/%s/transactions.json',
            rtrim(config('ucetni_prehled.fio.base_url'), '/'),
            $connection->api_token,
            $from,
            $to,
        );

        $response = Http::timeout(30)->acceptJson()->get($url);

        if ($response->status() === 409) {
            throw new RuntimeException('Fio API: příliš časté dotazy — zkuste to za 30 sekund.');
        }

        if ($response->status() === 500 || $response->status() === 404) {
            throw new RuntimeException('Fio API: neplatný token nebo chyba banky (HTTP '.$response->status().').');
        }

        $response->throw();

        $transactions = $response->json('accountStatement.transactionList.transaction') ?? [];

        return array_values(array_map(
            fn (array $row) => $this->mapTransaction($row),
            $transactions,
        ));
    }

    private function mapTransaction(array $row): TransactionData
    {
        $value = fn (int $column) => $row["column{$column}"]['value'] ?? null;

        $account = $value(2);
        $bankCode = $value(3);

        return new TransactionData(
            externalId: (string) $value(22),
            bookedOn: substr((string) $value(0), 0, 10),
            amount: number_format((float) $value(1), 2, '.', ''),
            currency: (string) ($value(14) ?: 'CZK'),
            counterpartyAccount: $account ? $account.($bankCode ? '/'.$bankCode : '') : null,
            counterpartyName: $value(10),
            variableSymbol: $value(5) !== null ? (string) $value(5) : null,
            constantSymbol: $value(4) !== null ? (string) $value(4) : null,
            specificSymbol: $value(6) !== null ? (string) $value(6) : null,
            message: $value(16) ?? $value(25),
            raw: $row,
        );
    }
}
