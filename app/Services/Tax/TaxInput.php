<?php

namespace App\Services\Tax;

/**
 * Vstupy výpočtu. Vše jsou celé koruny.
 */
final class TaxInput
{
    public function __construct(
        public readonly int $year,
        public readonly int $income,
        /** Sazba výdajového paušálu v %, nebo null při skutečných výdajích. */
        public readonly ?int $pausalPercent = 60,
        /** Skutečné výdaje — použijí se jen když $pausalPercent === null. */
        public readonly int $actualExpenses = 0,
        /** Vedlejší činnost (rodičovská, zaměstnání, důchod…). */
        public readonly bool $secondaryActivity = true,
        /**
         * Počet měsíců v roce, kdy platila vedlejší činnost (§ 9 zákona
         * 155/1995 Sb.) — null, když se přechod v roce neřešil a celý rok
         * se řídí jen $secondaryActivity. Když je < 12, roční rozhodná
         * částka se poměrně krátí za zbylé (hlavní) měsíce.
         */
        public readonly ?int $secondaryMonths = null,
        /** Stát je plátcem zdravotního pojistného → neplatí minimální vyměřovací základ. */
        public readonly bool $stateHealthPayer = true,
        /** Počet vyživovaných dětí, na které se uplatňuje zvýhodnění. */
        public readonly int $children = 0,
        public readonly bool $claimTaxpayerCredit = true,
        public readonly bool $claimChildren = true,
        public readonly bool $claimSpouseCredit = false,
        /** Zaplacené zálohy — ovlivňují jen doplatek, ne výši pojistného. */
        public readonly int $socialAdvancesPaid = 0,
        public readonly int $healthAdvancesPaid = 0,
    ) {}

    public function with(array $changes): self
    {
        return new self(
            year: $changes['year'] ?? $this->year,
            income: $changes['income'] ?? $this->income,
            pausalPercent: array_key_exists('pausalPercent', $changes) ? $changes['pausalPercent'] : $this->pausalPercent,
            actualExpenses: $changes['actualExpenses'] ?? $this->actualExpenses,
            secondaryActivity: $changes['secondaryActivity'] ?? $this->secondaryActivity,
            secondaryMonths: array_key_exists('secondaryMonths', $changes) ? $changes['secondaryMonths'] : $this->secondaryMonths,
            stateHealthPayer: $changes['stateHealthPayer'] ?? $this->stateHealthPayer,
            children: $changes['children'] ?? $this->children,
            claimTaxpayerCredit: $changes['claimTaxpayerCredit'] ?? $this->claimTaxpayerCredit,
            claimChildren: $changes['claimChildren'] ?? $this->claimChildren,
            claimSpouseCredit: $changes['claimSpouseCredit'] ?? $this->claimSpouseCredit,
            socialAdvancesPaid: $changes['socialAdvancesPaid'] ?? $this->socialAdvancesPaid,
            healthAdvancesPaid: $changes['healthAdvancesPaid'] ?? $this->healthAdvancesPaid,
        );
    }
}
