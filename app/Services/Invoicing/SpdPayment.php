<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Support\Text;

/**
 * QR platba — řetězec formátu SPD (Short Payment Descriptor) dle standardu
 * České bankovní asociace, tištěný jako QR kód na fakturách.
 */
class SpdPayment
{
    public function build(Invoice $invoice): ?string
    {
        $account = $invoice->bankAccount;

        if ($account === null || $invoice->currency !== 'CZK') {
            return null;
        }

        $iban = $account->iban ?: CzechIban::fromNational(
            $account->account_prefix,
            $account->account_number,
            $account->bank_code,
        );

        $parts = [
            'SPD*1.0',
            'ACC:'.$iban,
            'AM:'.$invoice->total,
            'CC:CZK',
        ];

        if ($invoice->variable_symbol) {
            $parts[] = 'X-VS:'.$invoice->variable_symbol;
        }

        if ($invoice->due_date) {
            $parts[] = 'DT:'.$invoice->due_date->format('Ymd');
        }

        $parts[] = 'MSG:'.$this->sanitizeMessage('Faktura '.$invoice->number);

        return implode('*', $parts);
    }

    /** SPD nepovoluje hvězdičky a diakritiku v MSG. */
    private function sanitizeMessage(string $message): string
    {
        $ascii = Text::ascii($message);

        return substr(str_replace('*', ' ', strtoupper($ascii)), 0, 60);
    }
}
