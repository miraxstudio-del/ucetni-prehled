<?php

namespace Tests\Feature;

use App\Services\Tax\TaxInput;
use App\Services\Tax\TaxProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Výhled běžícího roku: kdy poplatník při současném tempu protne hranici
 * bonusu, rozhodnou částku a limit DPH. Čas je v testech zmrazený —
 * projekce závisí na dnešním dni v roce.
 */
class TaxProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 19. 7. 2026 = 200. den roku (365 dní)
        $this->travelTo(Carbon::create(2026, 7, 19, 12));
    }

    private function input(array $changes = []): TaxInput
    {
        return (new TaxInput(
            year: 2026,
            income: 0,
            pausalPercent: 60,
            secondaryActivity: true,
            stateHealthPayer: true,
            children: 2,
        ))->with($changes);
    }

    private function projection(): TaxProjection
    {
        return app(TaxProjection::class);
    }

    public function test_no_projection_for_other_years(): void
    {
        $this->assertNull($this->projection()->build($this->input(['year' => 2025]), 100000));
    }

    /** Andreina reálná situace: 77 890 Kč k 19. 7. — bonus těsně na hraně. */
    public function test_bonus_on_track_but_tight(): void
    {
        $p = $this->projection()->build($this->input(), 77890);

        $this->assertSame(142149, $p['projectedIncome'], '77 890 / 200 dní × 365');

        $b = $p['bonus'];
        $this->assertFalse($b['reached']);
        $this->assertTrue($b['onTrack'], 'Projekce 142 149 > hranice 134 400');
        $this->assertSame(56510, $b['missing']);
        $this->assertSame(12, $b['estimatedMonth'], 'Při tomto tempu protne hranici až v prosinci');
    }

    public function test_bonus_at_risk_shows_needed_monthly_invoicing(): void
    {
        $p = $this->projection()->build($this->input(), 40000);

        $b = $p['bonus'];
        $this->assertFalse($b['onTrack'], 'Projekce 73 000 < 134 400');
        $this->assertSame(94400, $b['missing']);
        // 94 400 / 6 zbývajících měsíců (červenec–prosinec)
        $this->assertSame(15734, $b['neededPerMonth']);
        $this->assertNull($b['estimatedMonth'], 'Při tomto tempu letos hranici neprotne');
    }

    public function test_bonus_reached(): void
    {
        $p = $this->projection()->build($this->input(), 140000);

        $this->assertTrue($p['bonus']['reached']);
        $this->assertSame(0, $p['bonus']['missing']);
    }

    public function test_bonus_outlook_only_with_children(): void
    {
        $p = $this->projection()->build($this->input(['children' => 0]), 77890);

        $this->assertNull($p['bonus']);
    }

    public function test_social_threshold_measured_against_profit_not_income(): void
    {
        // příjem 77 890 → zisk (40 %) ~31 156; projekce zisku ~56 859 < 117 521
        $p = $this->projection()->build($this->input(), 77890);

        $s = $p['social'];
        $this->assertFalse($s['willCross']);
        $this->assertSame(117521, $s['threshold']);
        $this->assertLessThan(60000, $s['projectedProfit']);

        // příjem 200 000 → zisk zatím 80 000 (pod hranicí), projekce ~146 000 → protne
        $p = $this->projection()->build($this->input(), 200000);
        $this->assertTrue($p['social']['willCross']);
        $this->assertSame(10, $p['social']['estimatedMonth'], 'Zisk 400/den protne 117 521 v říjnu');

        // příjem 400 000 → zisk 160 000 UŽ hranici protnul → měsíc se nevrací
        $p = $this->projection()->build($this->input(), 400000);
        $this->assertTrue($p['social']['willCross']);
        $this->assertNull($p['social']['estimatedMonth']);
    }

    public function test_social_outlook_only_for_secondary_activity(): void
    {
        $p = $this->projection()->build($this->input(['secondaryActivity' => false]), 77890);

        $this->assertNull($p['social']);
    }

    public function test_vat_defer_advice_when_crossing_lands_at_year_end(): void
    {
        // 1,2 mil. za 200 dní → 6 000/den → 2 mil. protne 334. den (konec listopadu)
        $p = $this->projection()->build($this->input(), 1200000);

        $v = $p['vat'];
        $this->assertTrue($v['willCross']);
        $this->assertFalse($v['willCrossImmediate'], '2,19 mil. < 2 536 500');
        $this->assertSame(11, $v['estimatedMonth']);
        $this->assertTrue($v['deferAdvice'], 'Konec roku → rada posunout fakturaci do ledna');
    }

    public function test_vat_immediate_limit_flagged_without_defer_advice(): void
    {
        // 1,6 mil. za 200 dní → 8 000/den → projekce 2,92 mil.
        $p = $this->projection()->build($this->input(), 1600000);

        $v = $p['vat'];
        $this->assertTrue($v['willCrossImmediate']);
        $this->assertSame(9, $v['estimatedMonth'], '2 mil. protne v září');
        $this->assertFalse($v['deferAdvice'], 'Překročení v září posunem prosince nevyřešíš');
    }

    public function test_vat_safe_for_low_income(): void
    {
        $p = $this->projection()->build($this->input(), 77890);

        $this->assertFalse($p['vat']['willCross']);
        $this->assertNull($p['vat']['estimatedMonth']);
    }

    public function test_zero_income_does_not_divide_by_zero(): void
    {
        $p = $this->projection()->build($this->input(), 0);

        $this->assertSame(0, $p['projectedIncome']);
        $this->assertFalse($p['bonus']['onTrack']);
        $this->assertNull($p['bonus']['estimatedMonth']);
        $this->assertGreaterThan(0, $p['bonus']['neededPerMonth']);
    }

    public function test_tax_page_shows_outlook_for_current_year_only(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->get(route('tax.index', ['rok' => 2026]))
            ->assertOk()
            ->assertSee('Výhled roku 2026');

        $this->actingAs($user)->get(route('tax.index', ['rok' => 2025]))
            ->assertOk()
            ->assertDontSee('Výhled roku 2025');
    }
}
