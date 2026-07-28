<?php

namespace App\Services\Bank;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Text;
use Illuminate\Support\Collection;

/**
 * Export transakcí do formátu GPC/ABO (pro účetní software).
 * Výstup v CP1250 s CRLF — tak, jak jej české účetní programy očekávají.
 */
class GpcExporter
{
    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    public function export(BankAccount $account, Collection $transactions, string $dateFrom, string $dateTo): string
    {
        $transactions = $transactions->sortBy('booked_on')->values();

        $openingBalance = '0';
        $turnoverDebit = '0';
        $turnoverCredit = '0';

        foreach ($transactions as $transaction) {
            $halere = bcmul((string) $transaction->amount, '100', 0);

            if (bccomp($halere, '0', 0) < 0) {
                $turnoverDebit = bcadd($turnoverDebit, ltrim($halere, '-'), 0);
            } else {
                $turnoverCredit = bcadd($turnoverCredit, $halere, 0);
            }
        }

        $closingBalance = bcsub(bcadd($openingBalance, $turnoverCredit, 0), $turnoverDebit, 0);

        $lines = [$this->headerLine($account, $dateFrom, $dateTo, $openingBalance, $closingBalance, $turnoverDebit, $turnoverCredit)];

        foreach ($transactions as $index => $transaction) {
            $lines[] = $this->itemLine($account, $transaction, $index + 1);
        }

        $content = implode("\r\n", $lines)."\r\n";

        // mbstring CP1250 nezná — iconv ano; //TRANSLIT nahradí nepřevoditelné znaky
        $converted = @iconv('UTF-8', 'CP1250//TRANSLIT', $content);

        return $converted === false ? $content : $converted;
    }

    public function filename(string $dateFrom, string $dateTo): string
    {
        return sprintf('vypis-%s-%s.gpc', $dateFrom, $dateTo);
    }

    private function headerLine(BankAccount $account, string $from, string $to, string $opening, string $closing, string $debit, string $credit): string
    {
        return '074'
            .str_pad($account->account_number, 16, '0', STR_PAD_LEFT)
            .str_pad(mb_substr($this->ascii($account->name), 0, 20), 20)
            .$this->gpcDate($from)
            .$this->amountField($opening)
            .$this->amountField($closing)
            .$this->amountField($debit)
            .$this->amountField($credit)
            .str_pad('1', 3, '0', STR_PAD_LEFT)
            .$this->gpcDate($to);
    }

    private function itemLine(BankAccount $account, BankTransaction $transaction, int $sequence): string
    {
        $halere = bcmul((string) $transaction->amount, '100', 0);
        $isDebit = bccomp($halere, '0', 0) < 0;

        [$counterpartyNumber, $counterpartyBank] = $this->splitCounterparty($transaction->counterparty_account);

        return '075'
            .str_pad($account->account_number, 16, '0', STR_PAD_LEFT)
            .str_pad($counterpartyNumber, 16, '0', STR_PAD_LEFT)
            .str_pad((string) $sequence, 13, '0', STR_PAD_LEFT)
            .str_pad(ltrim($halere, '-'), 12, '0', STR_PAD_LEFT)
            .($isDebit ? '1' : '2')
            .str_pad((string) preg_replace('/\D/', '', (string) $transaction->variable_symbol), 10, '0', STR_PAD_LEFT)
            .str_pad($counterpartyBank, 4, '0', STR_PAD_LEFT)
            .str_pad((string) preg_replace('/\D/', '', (string) $transaction->constant_symbol), 4, '0', STR_PAD_LEFT)
            .str_pad((string) preg_replace('/\D/', '', (string) $transaction->specific_symbol), 10, '0', STR_PAD_LEFT)
            .$this->gpcDate($transaction->booked_on->format('Y-m-d'))
            .str_pad(mb_substr($this->ascii($transaction->counterparty_name ?? $transaction->message ?? ''), 0, 20), 20);
    }

    /** 14 číslic + znaménko dle ABO. */
    private function amountField(string $halere): string
    {
        $negative = bccomp($halere, '0', 0) < 0;

        return str_pad(ltrim($halere, '-'), 14, '0', STR_PAD_LEFT).($negative ? '-' : '+');
    }

    private function gpcDate(string $date): string
    {
        [$y, $m, $d] = explode('-', $date);

        return $d.$m.substr($y, -2);
    }

    /** @return array{0: string, 1: string} */
    private function splitCounterparty(?string $account): array
    {
        if (! $account || ! str_contains($account, '/')) {
            return [(string) $account, ''];
        }

        [$number, $bank] = explode('/', $account, 2);

        // předčíslí se v 16znakovém poli spojuje s číslem účtu
        return [str_replace('-', '', $number), $bank];
    }

    private function ascii(string $value): string
    {
        return Text::ascii($value);
    }
}
