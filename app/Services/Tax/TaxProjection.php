<?php

namespace App\Services\Tax;

use Illuminate\Support\Carbon;

/**
 * Výhled běžícího roku: z dosavadního tempa fakturace odhadne, kdy (a zda)
 * poplatník dosáhne na klíčové hranice.
 *
 * Tři hranice, každá se měří proti jiné veličině:
 *  - nárok na daňový bonus: PŘÍJMY ≥ 6× minimální mzdy (§ 35c ZDP) — tuhle
 *    hranici chce poplatník s dětmi PŘEKROČIT, jinak přijde o celý bonus;
 *  - rozhodná částka vedlejší činnosti: ZISK (základ daně) — od ní vzniká
 *    povinná účast na důchodovém pojištění za celý rok;
 *  - limit DPH: OBRAT za kalendářní rok — nad 2 mil. plátcem od 1. 1.
 *    dalšího roku, nad 2 536 500 okamžitě.
 *
 * Jde o orientační lineární projekci (příjmy ÷ uplynulé dny × celý rok) —
 * ne věštění; sezónnost neumí. Proto se všude ukazuje i surové tempo.
 */
class TaxProjection
{
    public function __construct(private readonly TaxCalculator $calculator) {}

    /** Projekce dává smysl jen pro běžící rok — jinak null. */
    public function build(TaxInput $in, int $ytdIncome, ?Carbon $asOf = null): ?array
    {
        $asOf = $asOf ?? now();

        if ($in->year !== $asOf->year) {
            return null;
        }

        $p = $this->calculator->parameters($in->year);

        $elapsedDays = max(1, $asOf->dayOfYear);
        $daysInYear = $asOf->isLeapYear() ? 366 : 365;
        $dailyRate = $ytdIncome / $elapsedDays;
        $projectedIncome = (int) round($dailyRate * $daysInYear);

        // Zisk přes kalkulačku (paušál se stropem / skutečné výdaje) — stejná
        // logika jako ostrý výpočet, žádná druhá verze pravdy.
        $projectedProfit = $this->calculator->compute($in->with(['income' => $projectedIncome]))->taxBase;
        $ytdProfit = $this->calculator->compute($in->with(['income' => $ytdIncome]))->taxBase;

        return [
            'asOf' => $asOf->toDateString(),
            'elapsedDays' => $elapsedDays,
            'ytdIncome' => $ytdIncome,
            'projectedIncome' => $projectedIncome,
            'projectedProfit' => $projectedProfit,
            'bonus' => $this->bonusOutlook($in, $p, $ytdIncome, $projectedIncome, $dailyRate, $daysInYear, $asOf),
            'social' => $this->socialOutlook($in, $p, $ytdProfit, $projectedProfit, $elapsedDays, $daysInYear),
            'vat' => $this->vatOutlook($p, $ytdIncome, $projectedIncome, $dailyRate, $daysInYear),
        ];
    }

    /**
     * Nárok na daňový bonus: příjmy musí do konce roku dosáhnout 6× minimální
     * mzdy. Hranice je tvrdá — o korunu míň a bonus je nula, ne poměrná část.
     */
    private function bonusOutlook(TaxInput $in, array $p, int $ytd, int $projected, float $dailyRate, int $daysInYear, Carbon $asOf): ?array
    {
        if (! $in->claimChildren || $in->children === 0) {
            return null;
        }

        $threshold = $p['bonus_min_income'];

        $missing = max(0, $threshold - $ytd);
        $monthsLeft = max(1, 12 - $asOf->month + 1);

        return [
            'threshold' => $threshold,
            'ytd' => $ytd,
            'reached' => $ytd >= $threshold,
            'onTrack' => $projected >= $threshold,
            'estimatedMonth' => $this->crossingMonth($threshold, $ytd, $dailyRate, $daysInYear),
            'missing' => $missing,
            'neededPerMonth' => $missing > 0 ? (int) ceil($missing / $monthsLeft) : 0,
        ];
    }

    /** Rozhodná částka vedlejší činnosti se měří proti ZISKU, ne příjmům. */
    private function socialOutlook(TaxInput $in, array $p, int $ytdProfit, int $projectedProfit, int $elapsedDays, int $daysInYear): ?array
    {
        if (! $in->secondaryActivity) {
            return null;
        }

        $threshold = $p['social_secondary_threshold'];
        $dailyProfit = $ytdProfit / max(1, $elapsedDays);

        return [
            'threshold' => $threshold,
            'ytdProfit' => $ytdProfit,
            'projectedProfit' => $projectedProfit,
            'willCross' => $projectedProfit >= $threshold,
            'estimatedMonth' => $this->crossingMonth($threshold, $ytdProfit, $dailyProfit, $daysInYear),
        ];
    }

    private function vatOutlook(array $p, int $ytd, int $projected, float $dailyRate, int $daysInYear): array
    {
        $limit = $p['vat_limit'];
        $month = $this->crossingMonth($limit, $ytd, $dailyRate, $daysInYear);

        return [
            'limit' => $limit,
            'immediateLimit' => $p['vat_limit_immediate'],
            'projected' => $projected,
            'willCross' => $projected >= $limit,
            'willCrossImmediate' => $projected >= $p['vat_limit_immediate'],
            'estimatedMonth' => $month,
            // Překročení až na konci roku → posunem prosincové fakturace do
            // ledna lze zůstat neplátcem (a v paušálním režimu) i další rok.
            'deferAdvice' => $month !== null && $month >= 11,
        ];
    }

    /**
     * Odhad měsíce, ve kterém kumulativ při současném tempu protne hranici.
     * Null = při tomto tempu letos neprotne (nebo tempo je nulové).
     */
    private function crossingMonth(int $threshold, int $ytd, float $dailyRate, int $daysInYear): ?int
    {
        if ($ytd >= $threshold) {
            return null; // už protnuto — stav řeší reached/willCross
        }

        if ($dailyRate <= 0) {
            return null;
        }

        $crossingDay = (int) ceil($threshold / $dailyRate);

        if ($crossingDay > $daysInYear) {
            return null;
        }

        return Carbon::create(now()->year)->startOfYear()->addDays($crossingDay - 1)->month;
    }
}
