<?php

namespace App\Services\Imports;

use App\Models\Client;
use App\Services\Audit\AuditLogger;

/**
 * Import klientů z CSV (migrace z Excelu). Šablona ke stažení v aplikaci.
 * Povinný je jen sloupec "nazev"; existující klienti (dle IČO nebo názvu)
 * se přeskakují.
 */
class ClientCsvImporter
{
    public const TEMPLATE = "nazev;ico;dic;ulice;mesto;psc;email;telefon;splatnost_dny;poznamka\n"
        ."Vzorová firma s.r.o.;12345678;CZ12345678;Dlouhá 12;Praha;11000;info@vzor.cz;+420600123456;14;VIP klient\n";

    public function __construct(
        private readonly CsvReader $reader,
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
            $line = $index + 2; // 1 = hlavička

            $name = $row['nazev'] ?? '';

            if ($name === '') {
                $errors[] = "Řádek {$line}: chybí název.";

                continue;
            }

            $ico = preg_replace('/\D/', '', $row['ico'] ?? '') ?: null;

            if ($ico !== null && strlen($ico) !== 8) {
                $errors[] = "Řádek {$line}: IČO musí mít 8 číslic.";

                continue;
            }

            // IČO i název jsou v DB zašifrované → duplicitu hledáme přes slepý index
            $exists = Client::query()
                ->when($ico, fn ($q) => $q->whereIco($ico))
                ->when(! $ico, fn ($q) => $q->whereName($name))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            Client::create([
                'name' => $name,
                'ico' => $ico,
                'dic' => $row['dic'] ?: null,
                'street' => $row['ulice'] ?: null,
                'city' => $row['mesto'] ?: null,
                'zip' => $row['psc'] ?: null,
                'email' => filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                'phone' => $row['telefon'] ?: null,
                'due_days' => is_numeric($row['splatnost_dny'] ?? '') ? (int) $row['splatnost_dny'] : null,
                'note' => $row['poznamka'] ?: null,
            ]);

            $imported++;
        }

        $this->audit->log('import.clients', meta: compact('imported', 'skipped'));

        return compact('imported', 'skipped', 'errors');
    }
}
