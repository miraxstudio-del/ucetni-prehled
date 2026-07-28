<?php

namespace App\Services\Bank;

use App\Models\BankConnection;

/**
 * Rozhraní bankovního konektoru (read-only přístup k transakcím).
 * První implementace: Fio banka. Další banky se přidají implementací
 * tohoto rozhraní — nic víc se v aplikaci měnit nemusí.
 */
interface BankConnector
{
    /** Identifikátor poskytovatele, např. 'fio'. */
    public function provider(): string;

    /**
     * Stáhne transakce od posledního úspěšného sync bodu (s přesahem;
     * deduplikaci řeší unikátní external_id).
     *
     * @return array<int, TransactionData>
     */
    public function fetchNewTransactions(BankConnection $connection): array;
}
