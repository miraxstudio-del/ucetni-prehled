<?php

namespace App\Services\Tax;

use InvalidArgumentException;

/**
 * Výpočet daně z příjmů a pojistného OSVČ.
 *
 * Zaokrouhlení je ověřeno zpětně proti podanému přiznání za rok 2025:
 *  - základ daně na celé stovky dolů (§ 16 ZDP),
 *  - vyměřovací základ i pojistné na celé koruny NAHORU.
 *
 * Peníze jdou přes bcmath, ne přes float.
 */
class TaxCalculator
{
    private const SCALE = 8;

    public function compute(TaxInput $in): TaxResult
    {
        $p = $this->parameters($in->year);
        $notes = [];

        $expenses = $this->expenses($in, $p, $notes);
        $taxBase = max(0, $in->income - $expenses);

        // Základ daně se zaokrouhluje na celé stovky dolů.
        $taxBaseRounded = intdiv($taxBase, 100) * 100;

        $taxBeforeCredits = $this->tax($taxBaseRounded, $p);

        $taxpayerCredit = $in->claimTaxpayerCredit ? $p['taxpayer_credit'] : 0;
        $spouseCredit = $in->claimSpouseCredit ? $p['spouse_credit'] : 0;

        $taxAfterCredits = max(0, $taxBeforeCredits - $taxpayerCredit - $spouseCredit);

        $childBenefit = $in->claimChildren ? $this->childBenefit($in->children, $p) : 0;

        [$taxDue, $bonus] = $this->settleChildBenefit($in, $p, $taxAfterCredits, $childBenefit, $notes);

        [$socialBase, $social, $socialNextAdvance] = $this->social($in, $p, $taxBase, $notes);
        [$healthBase, $health, $healthNextAdvance] = $this->health($in, $p, $taxBase, $notes);

        return new TaxResult(
            income: $in->income,
            expenses: $expenses,
            taxBase: $taxBase,
            taxBaseRounded: $taxBaseRounded,
            taxBeforeCredits: $taxBeforeCredits,
            taxpayerCredit: $taxpayerCredit,
            spouseCredit: $spouseCredit,
            taxAfterCredits: $taxAfterCredits,
            childBenefit: $childBenefit,
            taxDue: $taxDue,
            bonus: $bonus,
            socialBase: $socialBase,
            social: $social,
            socialDue: $social - $in->socialAdvancesPaid,
            socialNextAdvance: $socialNextAdvance,
            healthBase: $healthBase,
            health: $health,
            healthDue: $health - $in->healthAdvancesPaid,
            healthNextAdvance: $healthNextAdvance,
            notes: $notes,
        );
    }

    /** Parametry roku — bez ověřeného zdroje se nepočítá. */
    public function parameters(int $year): array
    {
        $p = config("tax.years.{$year}");

        if (! is_array($p)) {
            throw new InvalidArgumentException(
                "Pro rok {$year} nejsou v config/tax.php ověřené zákonné parametry. "
                .'Doplň je i se zdrojem — dopočítávat je odhadem by bylo horší než nepočítat vůbec.'
            );
        }

        return $p;
    }

    public function availableYears(): array
    {
        return array_map('intval', array_keys(config('tax.years', [])));
    }

    /**
     * Srovnání s paušální daní.
     *
     * Zásadní háček: v paušálním režimu NELZE uplatnit slevy ani daňové
     * zvýhodnění na děti, takže mizí i daňový bonus. U poplatníka, který dnes
     * bonus dostává, to bývá rozdíl desítek tisíc — proto se to počítá vždy,
     * i když by paušální daň na první pohled vypadala jednodušeji.
     *
     * Zálohy jsou minimální bez ohledu na to, že u vedlejší činnosti se státem
     * jako plátcem se dnes platí mnohem méně.
     */
    public function flatTaxComparison(TaxInput $in, TaxResult $current): array
    {
        $p = $this->parameters($in->year);
        $bands = $p['flat_tax'] ?? null;

        if (! is_array($bands)) {
            throw new InvalidArgumentException("Pro rok {$in->year} nejsou v config/tax.php parametry paušální daně.");
        }

        // Stropy pásem závisí i na typu činnosti (§ 7a ZDP): s paušálem 80 %
        // stačí 1. pásmo až do 2 mil., s 60 % do 1,5 mil.; jinak platí
        // základní 1 / 1,5 / 2 mil. Předpoklad: všechny příjmy jsou z jedné
        // činnosti (podmínka „aspoň 75 % příjmů“ je tím splněna).
        $ceilings = match ($in->pausalPercent) {
            80 => [1 => 2000000, 2 => 2000000, 3 => 2000000],
            60 => [1 => 1500000, 2 => 2000000, 3 => 2000000],
            default => [1 => 1000000, 2 => 1500000, 3 => 2000000],
        };

        $band = null;
        foreach ($bands as $number => $definition) {
            if ($in->income <= ($ceilings[$number] ?? $definition['income_limit'])) {
                $band = $number;

                break;
            }
        }

        if ($band === null) {
            return [
                'eligible' => false,
                'reason' => sprintf('Příjem %s Kč přesahuje limit paušálního režimu (%s Kč).',
                    number_format($in->income, 0, ',', ' '),
                    number_format(2000000, 0, ',', ' ')),
            ];
        }

        $annual = $bands[$band]['monthly'] * 12;

        // V paušálním režimu se neuplatní žádná sleva ani bonus.
        $flatNet = $in->income - $annual;

        return [
            'eligible' => true,
            'band' => $band,
            'monthly' => $bands[$band]['monthly'],
            'annual' => $annual,
            'netIncome' => $flatNet,
            'currentNetIncome' => $current->netIncome(),
            // kladné = paušální daň je lepší, záporné = horší
            'difference' => $flatNet - $current->netIncome(),
            'lostBonus' => $current->bonus,
        ];
    }

    private function expenses(TaxInput $in, array $p, array &$notes): int
    {
        if ($in->pausalPercent === null) {
            return $in->actualExpenses;
        }

        $cap = $p['pausal_caps'][$in->pausalPercent] ?? null;

        if ($cap === null) {
            throw new InvalidArgumentException("Výdajový paušál {$in->pausalPercent} % není pro rok {$in->year} v konfiguraci.");
        }

        $raw = bcdiv(bcmul((string) $in->income, (string) $in->pausalPercent, self::SCALE), '100', self::SCALE);
        $expenses = (int) $this->roundHalfUp($raw);

        if ($expenses > $cap) {
            $notes[] = sprintf(
                'Výdajový paušál %d %% je zastropován na %s Kč — nad tuto hranici se výdaje neuznávají.',
                $in->pausalPercent,
                number_format($cap, 0, ',', ' ')
            );

            $expenses = $cap;
        }

        return $expenses;
    }

    /** 15 % do 36násobku průměrné mzdy, nad ním 23 %. */
    private function tax(int $base, array $p): int
    {
        $threshold = $p['high_rate_threshold'];

        if ($base <= $threshold) {
            return (int) $this->ceilBc(bcdiv(bcmul((string) $base, $p['rate'], self::SCALE), '100', self::SCALE));
        }

        $low = bcdiv(bcmul((string) $threshold, $p['rate'], self::SCALE), '100', self::SCALE);
        $high = bcdiv(bcmul((string) ($base - $threshold), $p['rate_high'], self::SCALE), '100', self::SCALE);

        return (int) $this->ceilBc(bcadd($low, $high, self::SCALE));
    }

    private function childBenefit(int $children, array $p): int
    {
        $credits = $p['child_credits'];
        $total = 0;

        for ($i = 0; $i < $children; $i++) {
            // třetí a každé další dítě má stejnou částku jako třetí
            $total += $credits[min($i, count($credits) - 1)];
        }

        return $total;
    }

    /**
     * Zvýhodnění na děti je jediná sleva, která smí jít pod nulu — přebytek
     * se vrací jako daňový bonus. Podmínkou je příjem aspoň 6× minimální mzda.
     */
    private function settleChildBenefit(TaxInput $in, array $p, int $taxAfterCredits, int $childBenefit, array &$notes): array
    {
        if ($childBenefit <= $taxAfterCredits) {
            return [$taxAfterCredits - $childBenefit, 0];
        }

        $bonus = $childBenefit - $taxAfterCredits;

        if ($in->income < $p['bonus_min_income']) {
            $notes[] = sprintf(
                'Na daňový bonus není nárok — příjem %s Kč nedosahuje šestinásobku minimální mzdy (%s Kč).',
                number_format($in->income, 0, ',', ' '),
                number_format($p['bonus_min_income'], 0, ',', ' ')
            );

            return [0, 0];
        }

        return [0, $bonus];
    }

    /** @return array{0:int,1:int,2:int} vyměřovací základ, pojistné, nová záloha */
    private function social(TaxInput $in, array $p, int $taxBase, array &$notes): array
    {
        $threshold = $p['social_secondary_threshold'];
        $partialYear = $in->secondaryMonths !== null && $in->secondaryMonths < 12;

        if ($partialYear) {
            // Rozhodná částka se krátí o 1/12 (zaokrouhleno nahoru) za každý
            // měsíc, kdy činnost NEBYLA vedlejší — předpoklad: měsíc přechodu
            // se ještě počítá jako vedlejší (ověřit u ČSSZ, pokud přechod
            // nastal v polovině měsíce a záleží na tom).
            $monthlyReduction = (int) $this->ceilBc(bcdiv((string) $threshold, '12', self::SCALE));
            $mainMonths = 12 - $in->secondaryMonths;
            $threshold = max(0, $threshold - $monthlyReduction * $mainMonths);

            $notes[] = sprintf(
                'Vedlejší činnost jen %d %s v roce — roční rozhodná částka je poměrně snížena na %s Kč '
                .'(za zbylých %d %s jako hlavní činnost).',
                $in->secondaryMonths, $in->secondaryMonths === 1 ? 'měsíc' : 'měsíců',
                number_format($threshold, 0, ',', ' '),
                $mainMonths, $mainMonths === 1 ? 'měsíc' : 'měsíců'
            );
        }

        if ($in->secondaryActivity && $taxBase < $threshold) {
            $notes[] = sprintf(
                'Sociální pojištění se neplatí — zisk %s Kč nedosáhl rozhodné částky pro vedlejší činnost (%s Kč).',
                number_format($taxBase, 0, ',', ' '),
                number_format($threshold, 0, ',', ' ')
            );

            return [0, 0, 0];
        }

        if (! $in->secondaryActivity || $partialYear) {
            $notes[] = 'Hlavní činnost: výpočet nezohledňuje minimální vyměřovací základ — '
                .'pro hlavní činnost je potřeba ověřit minimální zálohu u ČSSZ.';
        }

        $base = (int) $this->ceilBc($this->percent((string) $taxBase, $p['social_base_share']));
        $premium = (int) $this->ceilBc($this->percent((string) $base, $p['social_rate']));

        $monthlyBase = bcdiv((string) $base, '12', self::SCALE);
        $advance = (int) $this->ceilBc($this->percent($monthlyBase, $p['social_rate']));

        return [$base, $premium, $advance];
    }

    /** @return array{0:int,1:int,2:int} vyměřovací základ, pojistné, nová záloha */
    private function health(TaxInput $in, array $p, int $taxBase, array &$notes): array
    {
        if (! $in->secondaryActivity && ! $in->stateHealthPayer) {
            $notes[] = 'Hlavní činnost: výpočet nezohledňuje minimální vyměřovací základ zdravotního pojištění.';
        }

        $base = (int) $this->ceilBc($this->percent((string) $taxBase, $p['health_base_share']));
        $premium = (int) $this->ceilBc($this->percent((string) $base, $p['health_rate']));

        $monthlyBase = bcdiv((string) $base, '12', self::SCALE);
        $advance = (int) $this->ceilBc($this->percent($monthlyBase, $p['health_rate']));

        if ($in->stateHealthPayer) {
            $notes[] = 'Stát je plátcem pojistného (péče o dítě) — neplatí minimální vyměřovací základ '
                .'a zálohy na zdravotní pojištění nejsou povinné; platí se doplatek po podání přehledu.';
        }

        return [$base, $premium, $advance];
    }

    private function percent(string $value, string $rate): string
    {
        return bcdiv(bcmul($value, $rate, self::SCALE), '100', self::SCALE);
    }

    private function ceilBc(string $value): string
    {
        if (bccomp($value, bcadd($value, '0', 0), self::SCALE) === 0) {
            return bcadd($value, '0', 0);
        }

        return bcadd($value, '1', 0);
    }

    private function roundHalfUp(string $value): string
    {
        return bcadd($value, '0.5', 0);
    }
}
