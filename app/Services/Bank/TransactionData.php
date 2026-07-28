<?php

namespace App\Services\Bank;

/**
 * Jednotná reprezentace bankovní transakce napříč zdroji (Fio API, GPC import).
 */
final readonly class TransactionData
{
    public function __construct(
        public string $externalId,
        public string $bookedOn,          // Y-m-d
        public string $amount,            // decimální řetězec, záporný = výdaj
        public string $currency,
        public ?string $counterpartyAccount,
        public ?string $counterpartyName,
        public ?string $variableSymbol,
        public ?string $constantSymbol,
        public ?string $specificSymbol,
        public ?string $message,
        public array $raw = [],
    ) {}
}
