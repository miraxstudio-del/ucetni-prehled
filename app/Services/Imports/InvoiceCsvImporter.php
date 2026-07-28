<?php

namespace App\Services\Imports;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Invoicing\InvoiceCalculator;
use App\Support\OrganizationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import faktur z CSV (migrace z Excelu) — jedna řádka = jedna faktura
 * s jednou souhrnnou položkou. Faktury se zakládají jako vystavené
 * s původními čísly; duplicitní čísla se přeskakují.
 */
class InvoiceCsvImporter
{
    public const TEMPLATE = "cislo;smer;klient;ico;vystaveno;splatnost;zaplaceno;popis;castka_bez_dph;sazba_dph;mena\n"
        ."2025-0001;vydana;Vzorová firma s.r.o.;12345678;15.01.2025;29.01.2025;28.01.2025;Vývoj webu;25000;0;CZK\n"
        ."FP-889;prijata;Dodavatel a.s.;87654321;20.01.2025;03.02.2025;;Licence software;4500;21;CZK\n";

    public function __construct(
        private readonly CsvReader $reader,
        private readonly InvoiceCalculator $calculator,
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $content): array
    {
        $rows = $this->reader->read($content);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            $number = $row['cislo'] ?? '';
            $direction = match (mb_strtolower($row['smer'] ?? '')) {
                'vydana', 'vydaná', 'issued' => 'issued',
                'prijata', 'přijatá', 'received' => 'received',
                default => null,
            };

            if ($number === '' || $direction === null) {
                $errors[] = "Řádek {$line}: chybí číslo dokladu nebo směr (vydana/prijata).";

                continue;
            }

            $issueDate = $this->parseDate($row['vystaveno'] ?? '');
            $dueDate = $this->parseDate($row['splatnost'] ?? '') ?? $issueDate;
            $paidAt = $this->parseDate($row['zaplaceno'] ?? '');

            if ($issueDate === null) {
                $errors[] = "Řádek {$line}: neplatné datum vystavení.";

                continue;
            }

            if (Invoice::query()->where('number', $number)->exists()) {
                $skipped++;

                continue;
            }

            $client = $this->resolveClient($row);

            if ($client === null) {
                $errors[] = "Řádek {$line}: chybí klient.";

                continue;
            }

            $vatPayer = $direction === 'received' || $this->context->current()->vat_payer;

            $calculated = $this->calculator->calculate([[
                'description' => $row['popis'] ?: 'Fakturované plnění',
                'quantity' => '1',
                'unit' => null,
                'unit_price' => $row['castka_bez_dph'] ?: '0',
                'vat_rate' => $row['sazba_dph'] ?: '0',
            ]], $vatPayer);

            DB::transaction(function () use ($number, $direction, $client, $issueDate, $dueDate, $paidAt, $row, $calculated) {
                $invoice = Invoice::create([
                    'direction' => $direction,
                    'type' => 'invoice',
                    'status' => $paidAt ? InvoiceStatus::Paid : InvoiceStatus::Issued,
                    'client_id' => $client->id,
                    'number' => $number,
                    'variable_symbol' => substr((string) preg_replace('/\D/', '', $number), 0, 10) ?: null,
                    'issue_date' => $issueDate,
                    'duzp' => $issueDate,
                    'due_date' => $dueDate,
                    'payment_method' => 'bank_transfer',
                    'currency' => strtoupper($row['mena'] ?: 'CZK'),
                    'subtotal' => $calculated['subtotal'],
                    'vat_total' => $calculated['vat_total'],
                    'total' => $calculated['total'],
                ]);

                if ($paidAt) {
                    $invoice->forceFill(['paid_at' => $paidAt])->save();
                }

                $invoice->items()->createMany($calculated['items']);
            });

            $imported++;
        }

        $this->audit->log('import.invoices', meta: compact('imported', 'skipped'));

        return compact('imported', 'skipped', 'errors');
    }

    private function resolveClient(array $row): ?Client
    {
        $ico = preg_replace('/\D/', '', $row['ico'] ?? '') ?: null;
        $name = $row['klient'] ?? '';

        // IČO i název jsou zašifrované → dohledáváme přes slepý index
        if ($ico) {
            $client = Client::whereIco($ico)->first();

            if ($client) {
                return $client;
            }
        }

        if ($name === '') {
            return null;
        }

        $client = Client::whereName($name)->first();

        return $client ?? Client::create(['name' => $name, 'ico' => $ico]);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
