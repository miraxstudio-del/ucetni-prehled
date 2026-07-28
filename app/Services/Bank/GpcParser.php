<?php

namespace App\Services\Bank;

/**
 * Parser bankovních výpisů ve formátu GPC/ABO (věty 074 hlavička, 076/075 položky).
 * Vstup bývá v kódování CP1250 — převádí se na UTF-8. Duplicitám při
 * opakovaném importu brání deterministické external_id (hash věty).
 *
 * Pozice polí věty 075 (1-based dle ABO):
 *   1–3 typ věty · 4–19 účet klienta · 20–35 protiúčet · 36–48 číslo dokladu
 *   49–60 částka v haléřích · 61 kód účtování (1 debet, 2 kredit, 4/5 storno)
 *   62–71 VS · 72–75 kód banky protistrany · 76–79 KS · 80–89 SS
 *   90–95 valuta DDMMRR · 96–115 doplňující údaj (název protistrany)
 */
class GpcParser
{
    /**
     * @return array{account_number: ?string, statement_date: ?string, transactions: array<int, TransactionData>}
     */
    public function parse(string $content): array
    {
        $content = $this->toUtf8($content);

        $accountNumber = null;
        $statementDate = null;
        $transactions = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            $type = substr($line, 0, 3);

            if ($type === '074') {
                $accountNumber = ltrim(trim(substr($line, 3, 16)), '0') ?: null;
                $statementDate = $this->parseDate(substr($line, 122, 6)) ?? $statementDate;
            }

            if ($type === '075' && strlen($line) >= 95) {
                $transactions[] = $this->parseItem($line);
            }
        }

        return [
            'account_number' => $accountNumber,
            'statement_date' => $statementDate,
            'transactions' => $transactions,
        ];
    }

    private function parseItem(string $line): TransactionData
    {
        $amountHalere = (int) ltrim(substr($line, 48, 12), '0');
        $code = substr($line, 60, 1);

        // 1 = debet, 2 = kredit, 4 = storno debetu, 5 = storno kreditu
        $sign = in_array($code, ['1', '5'], true) ? '-' : '';
        $amount = bcdiv($sign.$amountHalere, '100', 2);

        $counterparty = ltrim(trim(substr($line, 19, 16)), '0');
        $counterpartyBank = trim(substr($line, 71, 4));

        return new TransactionData(
            // deterministické ID: stejná věta se podruhé neimportuje
            externalId: 'gpc-'.hash('sha256', trim($line)),
            bookedOn: $this->parseDate(substr($line, 89, 6)) ?? now()->format('Y-m-d'),
            amount: $amount,
            currency: 'CZK',
            counterpartyAccount: $counterparty !== '' ? $counterparty.($counterpartyBank !== '' ? '/'.$counterpartyBank : '') : null,
            counterpartyName: trim(substr($line, 95, 20)) ?: null,
            variableSymbol: ltrim(trim(substr($line, 61, 10)), '0') ?: null,
            constantSymbol: ltrim(trim(substr($line, 75, 4)), '0') ?: null,
            specificSymbol: ltrim(trim(substr($line, 79, 10)), '0') ?: null,
            message: trim(substr($line, 95, 20)) ?: null,
            raw: ['gpc_line' => trim($line)],
        );
    }

    /** DDMMRR → Y-m-d (roky 00–49 = 2000+, 50–99 = 1900+). */
    private function parseDate(string $value): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', trim($value), $m)) {
            return null;
        }

        $year = (int) $m[3] < 50 ? 2000 + (int) $m[3] : 1900 + (int) $m[3];

        return checkdate((int) $m[2], (int) $m[1], $year)
            ? sprintf('%d-%02d-%02d', $year, $m[2], $m[1])
            : null;
    }

    private function toUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // mbstring CP1250 nezná — iconv ano (Windows i Linux)
        $converted = @iconv('CP1250', 'UTF-8//IGNORE', $content);

        return $converted === false ? $content : $converted;
    }
}
