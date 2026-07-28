<?php

namespace App\Services\Tax;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\TaxProfile;

/**
 * Sesbírá příjmy za rok a spočítá daň, sociální a zdravotní — včetně
 * scénářů slev, aby šlo porovnat, co která sleva udělá.
 */
class TaxYearReport
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxProjection $projection,
    ) {}

    public function build(Organization $organization, int $year): array
    {
        $profile = $this->profile($organization, $year);

        $invoiceIncome = $this->invoiceIncome($year);
        $closed = config("tax.closed_years.{$year}");

        // U uzavřeného roku je závazné podané přiznání, ne součet faktur.
        $override = $profile->income_override !== null
            ? (int) round((float) $profile->income_override)
            : ($closed['income'] ?? null);

        $income = $override ?? $invoiceIncome;

        $result = $this->calculator->compute($profile->toInput($income));

        return [
            'year' => $year,
            'profile' => $profile,
            'income' => $income,
            'invoiceIncome' => $invoiceIncome,
            'usesOverride' => $override !== null,
            'closed' => $closed,
            'discrepancy' => $override !== null ? $invoiceIncome - $override : 0,
            'result' => $result,
            'flatTax' => $this->calculator->flatTaxComparison($profile->toInput($income), $result),
            'scenarios' => $this->scenarios($profile, $income),
            'monthly' => $this->monthlyIncome($year),
            'parameters' => $this->calculator->parameters($year),
            // Výhled jen pro běžící rok, vždy z faktur (ne z přiznání) —
            // predikce sleduje skutečné tempo fakturace.
            'projection' => $this->projection->build($profile->toInput($invoiceIncome), $invoiceIncome),
        ];
    }

    public function profile(Organization $organization, int $year): TaxProfile
    {
        return TaxProfile::firstOrCreate(
            ['organization_id' => $organization->id, 'year' => $year],
            ['year' => $year],
        );
    }

    /**
     * Součet vydaných faktur za rok. Proformy se nepočítají (nejsou daňový
     * doklad), stornované taky ne, opravné doklady se odečítají.
     */
    public function invoiceIncome(int $year): int
    {
        return (int) round(array_sum(array_column($this->monthlyIncome($year), 'income')));
    }

    /**
     * Příjmy po měsících — ať je vidět, jak rok narůstá.
     *
     * Seskupuje se v PHP, ne v SQL: MONTH() na SQLite (testy) neexistuje
     * a faktur jsou za rok desítky, ne miliony.
     */
    public function monthlyIncome(int $year): array
    {
        $rows = Invoice::query()
            ->where('direction', InvoiceDirection::Issued)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->whereIn('type', [DocumentType::Invoice->value, DocumentType::CreditNote->value])
            ->whereYear('issue_date', $year)
            ->get(['issue_date', 'type', 'total']);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['income' => 0, 'running' => 0];
        }

        foreach ($rows as $invoice) {
            $amount = (int) round((float) $invoice->total);

            // opravný daňový doklad příjem snižuje
            if ($invoice->type === DocumentType::CreditNote) {
                $amount = -$amount;
            }

            $months[(int) $invoice->issue_date->month]['income'] += $amount;
        }

        $running = 0;
        foreach ($months as $m => $data) {
            $running += $data['income'];
            $months[$m]['running'] = $running;
        }

        return $months;
    }

    /**
     * Čtyři kombinace slev: děti ano/ne × manžel(ka) ano/ne.
     * Sleva na poplatníka se needituje — ta náleží vždy.
     */
    public function scenarios(TaxProfile $profile, int $income): array
    {
        $combinations = [
            'none' => ['label' => 'Bez slev na děti i manžela', 'children' => false, 'spouse' => false],
            'children' => ['label' => 'Jen zvýhodnění na děti', 'children' => true, 'spouse' => false],
            'spouse' => ['label' => 'Jen sleva na manžela', 'children' => false, 'spouse' => true],
            'both' => ['label' => 'Děti i manžel', 'children' => true, 'spouse' => true],
        ];

        $out = [];

        foreach ($combinations as $key => $c) {
            $out[$key] = [
                'label' => $c['label'],
                'children' => $c['children'],
                'spouse' => $c['spouse'],
                'result' => $this->calculator->compute($profile->toInput($income, [
                    'claimChildren' => $c['children'],
                    'claimSpouseCredit' => $c['spouse'],
                ])),
            ];
        }

        return $out;
    }
}
