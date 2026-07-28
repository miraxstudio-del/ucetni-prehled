<?php

namespace App\Services\Invoicing;

use App\Enums\DocumentType;
use App\Models\NumberSeries;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Číselné řady dokladů — atomická alokace čísel (uzamčení řádku v transakci),
 * aby ani při souběhu nevznikla dvě stejná čísla faktur.
 */
class NumberSeriesService
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Alokuje další číslo z řady. Vrací [number, variableSymbol].
     *
     * @return array{0: string, 1: string}
     */
    public function allocate(NumberSeries $series): array
    {
        return DB::transaction(function () use ($series) {
            $locked = NumberSeries::query()
                ->whereKey($series->id)
                ->lockForUpdate()
                ->firstOrFail();

            $number = $locked->formatNumber($locked->next_number);
            $locked->increment('next_number');

            // variabilní symbol = číslice z čísla dokladu (max 10)
            $variableSymbol = substr((string) preg_replace('/\D/', '', $number), 0, 10);

            return [$number, $variableSymbol];
        });
    }

    /** Vrátí výchozí řadu pro typ dokladu a aktuální rok; založí ji, pokud chybí. */
    public function defaultFor(DocumentType $type, ?int $year = null): NumberSeries
    {
        $year ??= (int) now()->format('Y');

        $series = NumberSeries::query()
            ->where('document_type', $type)
            ->where('year', $year)
            ->orderByDesc('is_default')
            ->first();

        if ($series !== null) {
            return $series;
        }

        return NumberSeries::create([
            'organization_id' => $this->context->id(),
            'document_type' => $type,
            'name' => 'Výchozí řada',
            'format' => match ($type) {
                DocumentType::Invoice => '{YYYY}-{NNNN}',
                DocumentType::Proforma => 'PF{YYYY}-{NNNN}',
                DocumentType::CreditNote => 'OP{YYYY}-{NNNN}',
            },
            'year' => $year,
            'next_number' => 1,
            'is_default' => true,
        ]);
    }
}
