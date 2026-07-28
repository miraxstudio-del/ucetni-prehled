<?php

namespace App\Services\Tax;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Výsledek výpočtu. Nese i mezikroky — u daní musí být vidět, jak se
 * k číslu došlo, jinak se nedá zkontrolovat.
 */
final class TaxResult implements Arrayable
{
    public function __construct(
        public readonly int $income,
        public readonly int $expenses,
        public readonly int $taxBase,
        public readonly int $taxBaseRounded,
        public readonly int $taxBeforeCredits,
        public readonly int $taxpayerCredit,
        public readonly int $spouseCredit,
        public readonly int $taxAfterCredits,
        public readonly int $childBenefit,
        /** Daň k zaplacení (nikdy záporná). */
        public readonly int $taxDue,
        /** Daňový bonus k vrácení od FÚ. */
        public readonly int $bonus,
        public readonly int $socialBase,
        public readonly int $social,
        public readonly int $socialDue,
        public readonly int $socialNextAdvance,
        public readonly int $healthBase,
        public readonly int $health,
        public readonly int $healthDue,
        public readonly int $healthNextAdvance,
        /** @var list<string> Vysvětlivky a upozornění pro uživatele. */
        public readonly array $notes = [],
    ) {}

    /** Kolik celkem odejde (nebo přijde, je-li záporné) po vypořádání roku. */
    public function netSettlement(): int
    {
        return $this->taxDue - $this->bonus + $this->socialDue + $this->healthDue;
    }

    /** Co z vydělaného zbyde po dani a pojistném. */
    public function netIncome(): int
    {
        return $this->income - $this->taxDue + $this->bonus - $this->social - $this->health;
    }

    public function toArray(): array
    {
        return [
            'income' => $this->income,
            'expenses' => $this->expenses,
            'taxBase' => $this->taxBase,
            'taxBaseRounded' => $this->taxBaseRounded,
            'taxBeforeCredits' => $this->taxBeforeCredits,
            'taxpayerCredit' => $this->taxpayerCredit,
            'spouseCredit' => $this->spouseCredit,
            'taxAfterCredits' => $this->taxAfterCredits,
            'childBenefit' => $this->childBenefit,
            'taxDue' => $this->taxDue,
            'bonus' => $this->bonus,
            'socialBase' => $this->socialBase,
            'social' => $this->social,
            'socialDue' => $this->socialDue,
            'socialNextAdvance' => $this->socialNextAdvance,
            'healthBase' => $this->healthBase,
            'health' => $this->health,
            'healthDue' => $this->healthDue,
            'healthNextAdvance' => $this->healthNextAdvance,
            'netSettlement' => $this->netSettlement(),
            'netIncome' => $this->netIncome(),
            'notes' => $this->notes,
        ];
    }
}
