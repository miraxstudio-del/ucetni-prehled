<?php

namespace Tests\Feature;

use App\Services\Tax\TaxCalculator;
use App\Services\Tax\TaxInput;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Kontrola proti SKUTEČNĚ PODANÉMU přiznání za rok 2025 (Andrea Letochová).
 * Když se tenhle test rozbije, čísla v aplikaci nesedí s realitou a nesmí se
 * podle nich nic platit.
 */
class TaxCalculatorTest extends TestCase
{
    private function calc(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    /** Vstupy přesně podle podaného přiznání za 2025. */
    private function year2025(array $changes = []): TaxInput
    {
        return (new TaxInput(
            year: 2025,
            income: 357815,
            pausalPercent: 60,
            secondaryActivity: true,
            stateHealthPayer: true,
            children: 2,
            socialAdvancesPaid: 0,
            healthAdvancesPaid: 3204,
        ))->with($changes);
    }

    public function test_matches_the_filed_2025_tax_return(): void
    {
        $r = $this->calc()->compute($this->year2025());

        $this->assertSame(214689, $r->expenses, 'Paušál 60 % z 357 815');
        $this->assertSame(143126, $r->taxBase, 'Základ daně');
        $this->assertSame(143100, $r->taxBaseRounded, 'Zaokrouhleno na celé stovky dolů');
        $this->assertSame(21465, $r->taxBeforeCredits, 'Daň 15 %');
        $this->assertSame(30840, $r->taxpayerCredit, 'Sleva na poplatníka');
        $this->assertSame(0, $r->taxAfterCredits, 'Sleva přebila daň');
        $this->assertSame(37524, $r->childBenefit, 'Zvýhodnění na 2 děti (15 204 + 22 320)');
        $this->assertSame(0, $r->taxDue);
        $this->assertSame(37524, $r->bonus, 'Daňový bonus dle přiznání');
    }

    public function test_matches_the_filed_2025_social_insurance(): void
    {
        $r = $this->calc()->compute($this->year2025());

        $this->assertSame(78720, $r->socialBase, '55 % z 143 126, nahoru');
        $this->assertSame(22987, $r->social, '29,2 % z 78 720, nahoru');
        $this->assertSame(22987, $r->socialDue, 'Doplatek dle přehledu plateb');
        $this->assertSame(1916, $r->socialNextAdvance, 'Nová záloha dle přehledu plateb');
    }

    public function test_matches_the_filed_2025_health_insurance(): void
    {
        $r = $this->calc()->compute($this->year2025());

        $this->assertSame(71563, $r->healthBase, '50 % z 143 126');
        $this->assertSame(9662, $r->health, '13,5 % z 71 563, nahoru');
        $this->assertSame(6458, $r->healthDue, 'Doplatek dle přehledu plateb');
        $this->assertSame(806, $r->healthNextAdvance, 'Nová záloha dle přehledu plateb');
    }

    public function test_reference_values_in_config_match_the_calculation(): void
    {
        $expected = config('tax.closed_years.2025.expected');
        $r = $this->calc()->compute($this->year2025());

        $this->assertSame($expected['tax_base'], $r->taxBase);
        $this->assertSame($expected['tax_base_rounded'], $r->taxBaseRounded);
        $this->assertSame($expected['tax'], $r->taxBeforeCredits);
        $this->assertSame($expected['bonus'], $r->bonus);
        $this->assertSame($expected['social'], $r->social);
        $this->assertSame($expected['health'], $r->health);
    }

    public function test_social_is_not_paid_below_the_secondary_threshold(): void
    {
        // zisk pod rozhodnou částkou 111 736 (2025)
        $r = $this->calc()->compute($this->year2025(['income' => 200000]));

        $this->assertSame(80000, $r->taxBase);
        $this->assertSame(0, $r->social, 'Vedlejší činnost pod rozhodnou částkou neplatí sociální');
        $this->assertNotSame(0, $r->health, 'Zdravotní se platí vždy ze skutečného základu');
    }

    public function test_main_activity_pays_social_even_below_the_threshold(): void
    {
        $r = $this->calc()->compute($this->year2025([
            'income' => 200000,
            'secondaryActivity' => false,
        ]));

        $this->assertNotSame(0, $r->social);
    }

    /**
     * Přechod vedlejší → hlavní v půlce roku (např. konec rodičovské) —
     * roční rozhodná částka 2026 (117 521) se krátí o 1/12 zaokrouhlené
     * nahoru (9 794) za každý ze 6 měsíců hlavní činnosti: 117 521 − 6×9 794
     * = 58 757. Zisk 70 000 by pod celoroční částkou vyšel jako osvobozený,
     * ale pod poměrnou už ne — proto se tím dá poznat, kdyby se proporcování
     * ztratilo.
     */
    public function test_partial_year_secondary_activity_prorates_threshold(): void
    {
        $r = $this->calc()->compute($this->year2025([
            'year' => 2026,
            'income' => 175000, // 60% paušál → základ 70 000
            'secondaryMonths' => 6,
        ]));

        $this->assertSame(70000, $r->taxBase);
        $this->assertSame(38500, $r->socialBase, '55 % z 70 000');
        $this->assertSame(11242, $r->social, '29,2 % z 38 500 — pod poměrnou částkou (58 757) se už platí');
        $this->assertStringContainsString('poměrně snížena na 58 757 Kč', implode(' ', $r->notes));
    }

    public function test_partial_year_below_prorated_threshold_still_exempt(): void
    {
        $r = $this->calc()->compute($this->year2025([
            'year' => 2026,
            'income' => 100000, // základ 40 000 — pod poměrnou částkou 58 757
            'secondaryMonths' => 6,
        ]));

        $this->assertSame(0, $r->social);
    }

    public function test_secondary_months_equal_to_twelve_behaves_like_whole_year(): void
    {
        $withTwelve = $this->calc()->compute($this->year2025(['year' => 2026, 'income' => 175000, 'secondaryMonths' => 12]));
        $withNull = $this->calc()->compute($this->year2025(['year' => 2026, 'income' => 175000, 'secondaryMonths' => null]));

        $this->assertSame($withNull->social, $withTwelve->social);
        $this->assertSame($withNull->socialBase, $withTwelve->socialBase);
    }

    public function test_bonus_requires_six_times_the_minimum_wage(): void
    {
        // příjem pod 124 800 Kč (2025) → bonus nenáleží
        $r = $this->calc()->compute($this->year2025(['income' => 100000]));

        $this->assertSame(0, $r->bonus);
        $this->assertSame(0, $r->taxDue);
        $this->assertNotEmpty($r->notes);
    }

    public function test_pausal_is_capped(): void
    {
        $r = $this->calc()->compute($this->year2025(['income' => 2500000]));

        $this->assertSame(1200000, $r->expenses, 'Strop výdajů u 60% paušálu');
        $this->assertSame(1300000, $r->taxBase);
    }

    public function test_high_rate_applies_above_the_threshold(): void
    {
        // základ nad 1 676 052 Kč (2025) se nad hranicí daní 23 %
        $r = $this->calc()->compute($this->year2025([
            'income' => 5000000,
            'pausalPercent' => null,
            'actualExpenses' => 3000000,
        ]));

        $this->assertSame(2000000, $r->taxBase);
        // 15 % z 1 676 000 + 23 % ze zbytku (základ zaokrouhlen na stovky dolů)
        $expected = (int) (1676052 * 0.15) + (int) round((2000000 - 1676052) * 0.23);
        $this->assertEqualsWithDelta($expected, $r->taxBeforeCredits, 2);
    }

    public function test_2026_uses_its_own_parameters(): void
    {
        $r = $this->calc()->compute($this->year2025(['year' => 2026, 'income' => 117000]));

        // rozhodná částka 2026 je 117 521 → zisk 46 800 je pod ní
        $this->assertSame(0, $r->social);

        $params = $this->calc()->parameters(2026);
        $this->assertSame(117521, $params['social_secondary_threshold']);
        $this->assertSame(1762812, $params['high_rate_threshold']);
    }

    /**
     * Pro poplatníka s daňovým bonusem je paušální daň past — bonus v ní
     * zaniká. Kdyby to modul spočítal obráceně, poradil by drahou chybu.
     */
    public function test_flat_tax_is_much_worse_when_a_child_bonus_is_lost(): void
    {
        $input = $this->year2025();
        $result = $this->calc()->compute($input);
        $flat = $this->calc()->flatTaxComparison($input, $result);

        $this->assertTrue($flat['eligible']);
        $this->assertSame(1, $flat['band'], 'Příjem 357 815 spadá do 1. pásma');
        $this->assertSame(8716 * 12, $flat['annual']);
        $this->assertSame(37524, $flat['lostBonus']);

        // dnešní režim: 357 815 − 0 daň + 37 524 bonus − 22 987 − 9 662
        $this->assertSame(362690, $flat['currentNetIncome']);
        // paušální daň: 357 815 − 104 592, žádný bonus
        $this->assertSame(253223, $flat['netIncome']);

        $this->assertLessThan(0, $flat['difference'], 'Paušální daň musí vyjít hůř');
        $this->assertSame(-109467, $flat['difference']);
    }

    /**
     * Stropy pásem závisí na typu činnosti (§ 7a ZDP): s paušálem 60 % sahá
     * 1. pásmo až do 1,5 mil. Kč — 1,2 mil. tedy patří do 1. pásma, ne do 2.
     * (Dřív se počítalo jen s obecnými stropy 1/1,5/2 mil. — nepřesnost
     * odhalená srovnáním s projektem myinvoice.)
     */
    public function test_flat_tax_band_follows_income_and_activity(): void
    {
        // 60% paušál: 1,2 mil. je pořád 1. pásmo
        $input = $this->year2025(['income' => 1200000]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));
        $this->assertSame(1, $flat['band']);

        // 60% paušál: 1,7 mil. už spadne do 2. pásma
        $input = $this->year2025(['income' => 1700000]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));
        $this->assertSame(2, $flat['band']);

        // 40% paušál: 1,2 mil. je 2. pásmo (základní stropy)
        $input = $this->year2025(['income' => 1200000, 'pausalPercent' => 40]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));
        $this->assertSame(2, $flat['band']);

        // 80% paušál: i 1,9 mil. je 1. pásmo
        $input = $this->year2025(['income' => 1900000, 'pausalPercent' => 80]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));
        $this->assertSame(1, $flat['band']);
    }

    public function test_flat_tax_is_refused_above_the_limit(): void
    {
        $input = $this->year2025(['income' => 3000000]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));

        $this->assertFalse($flat['eligible']);
        $this->assertStringContainsString('přesahuje limit', $flat['reason']);
    }

    public function test_flat_tax_2026_uses_the_raised_first_band(): void
    {
        $input = $this->year2025(['year' => 2026]);
        $flat = $this->calc()->flatTaxComparison($input, $this->calc()->compute($input));

        $this->assertSame(9984, $flat['monthly'], '1. pásmo se pro 2026 zvedlo z 8 716 na 9 984');
        $this->assertSame(119808, $flat['annual']);
    }

    public function test_unknown_year_refuses_to_guess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->compute($this->year2025(['year' => 2030]));
    }
}
