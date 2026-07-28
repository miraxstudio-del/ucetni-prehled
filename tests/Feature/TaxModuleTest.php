<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\TaxProfile;
use App\Services\Tax\TaxYearReport;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxModuleTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(int $organizationId, string $date, int $total, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'organization_id' => $organizationId,
            'direction' => InvoiceDirection::Issued,
            'type' => DocumentType::Invoice,
            'status' => InvoiceStatus::Paid,
            'issue_date' => $date,
            'total' => $total,
            'subtotal' => $total,
        ], $overrides));
    }

    public function test_page_loads_and_shows_the_three_columns(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();

        $response = $this->actingAs($user)->get(route('tax.index', ['rok' => 2026]));

        $response->assertOk()
            ->assertSee('Daň z příjmů')
            ->assertSee('Sociální pojištění')
            ->assertSee('Zdravotní pojištění')
            ->assertSee('Scénáře slev');
    }

    public function test_income_is_summed_from_issued_invoices(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $this->invoice($organization->id, '2026-02-10', 10000);
        $this->invoice($organization->id, '2026-03-15', 5000);

        // nezapočítá se: jiný rok, storno, proforma, přijatá faktura
        $this->invoice($organization->id, '2025-05-01', 999999);
        $this->invoice($organization->id, '2026-04-01', 777, ['status' => InvoiceStatus::Cancelled]);
        $this->invoice($organization->id, '2026-04-02', 888, ['type' => DocumentType::Proforma]);
        $this->invoice($organization->id, '2026-04-03', 555, ['direction' => InvoiceDirection::Received]);

        $this->assertSame(15000, app(TaxYearReport::class)->invoiceIncome(2026));
    }

    public function test_credit_note_reduces_income(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $this->invoice($organization->id, '2026-02-10', 10000);
        $this->invoice($organization->id, '2026-02-20', 2000, ['type' => DocumentType::CreditNote]);

        $this->assertSame(8000, app(TaxYearReport::class)->invoiceIncome(2026));
    }

    public function test_monthly_breakdown_accumulates(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $this->invoice($organization->id, '2026-01-10', 1000);
        $this->invoice($organization->id, '2026-03-10', 500);

        $monthly = app(TaxYearReport::class)->monthlyIncome(2026);

        $this->assertSame(1000, $monthly[1]['income']);
        $this->assertSame(0, $monthly[2]['income']);
        $this->assertSame(500, $monthly[3]['income']);
        $this->assertSame(1000, $monthly[2]['running'], 'Únor nic nepřidal, ale kumulativ drží');
        $this->assertSame(1500, $monthly[12]['running']);
    }

    /** Uzavřený rok se počítá z přiznání, ne ze součtu faktur. */
    public function test_closed_year_uses_the_filed_return_not_the_invoices(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $this->invoice($organization->id, '2025-06-01', 358957);

        TaxProfile::create([
            'organization_id' => $organization->id,
            'year' => 2025,
            'children' => 2,
            'health_advances_paid' => 3204,
        ]);

        $report = app(TaxYearReport::class)->build($organization, 2025);

        $this->assertTrue($report['usesOverride']);
        $this->assertSame(357815, $report['income'], 'Závazné je podané přiznání');
        $this->assertSame(358957, $report['invoiceIncome']);
        $this->assertSame(1142, $report['discrepancy'], 'Rozdíl se nezamlčuje, ukáže se');

        // celá cesta od faktur po výsledek musí sedět s podaným přiznáním
        $this->assertSame(37524, $report['result']->bonus);
        $this->assertSame(22987, $report['result']->social);
        $this->assertSame(9662, $report['result']->health);
        $this->assertSame(6458, $report['result']->healthDue);
    }

    public function test_four_scenarios_are_computed(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $report = app(TaxYearReport::class)->build($organization, 2025);

        $this->assertSame(['none', 'children', 'spouse', 'both'], array_keys($report['scenarios']));

        // bez dětí není bonus, s dětmi ano
        $this->assertSame(0, $report['scenarios']['none']['result']->bonus);
        $this->assertSame(0, $report['scenarios']['children']['result']->bonus, 'Profil má zatím 0 dětí');
    }

    public function test_scenarios_differ_once_children_are_set(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        TaxProfile::create([
            'organization_id' => $organization->id,
            'year' => 2025,
            'children' => 2,
        ]);

        $report = app(TaxYearReport::class)->build($organization, 2025);

        $this->assertSame(37524, $report['scenarios']['children']['result']->bonus);
        $this->assertSame(0, $report['scenarios']['none']['result']->bonus);
    }

    public function test_settings_can_be_saved(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();

        $this->actingAs($user)->put(route('tax.update', 2026), [
            'expense_mode' => 'pausal',
            'pausal_percent' => 60,
            'children' => 2,
            'secondary_activity' => '1',
            'state_health_payer' => '1',
            'claim_taxpayer_credit' => '1',
            'claim_children' => '1',
            'social_advances_paid' => 0,
            'health_advances_paid' => 3204,
        ])->assertRedirect(route('tax.index', ['rok' => 2026]));

        $profile = TaxProfile::where('organization_id', $organization->id)->where('year', 2026)->firstOrFail();

        $this->assertSame(2, $profile->children);
        $this->assertSame(60, $profile->pausal_percent);
        $this->assertTrue($profile->secondary_activity);
        $this->assertFalse($profile->claim_spouse_credit, 'Nezaškrtnuté políčko musí zůstat vypnuté');
    }

    public function test_secondary_activity_until_prorates_the_saved_report(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();

        $this->actingAs($user)->put(route('tax.update', 2026), [
            'expense_mode' => 'pausal',
            'pausal_percent' => 60,
            'children' => 0,
            'secondary_activity' => '1',
            'secondary_activity_until' => '2026-06-30',
            'state_health_payer' => '1',
            'claim_taxpayer_credit' => '1',
            'income_override' => 175000, // základ 70 000 po 60% paušálu
        ])->assertRedirect(route('tax.index', ['rok' => 2026]));

        $profile = TaxProfile::where('organization_id', $organization->id)->where('year', 2026)->firstOrFail();
        $this->assertSame('2026-06-30', $profile->secondary_activity_until->toDateString());

        $report = app(TaxYearReport::class)->build($organization, 2026);

        // 6 měsíců vedlejší → poměrná částka 58 757, zisk 70 000 ji přesahuje
        $this->assertNotSame(0, $report['result']->social);
    }

    public function test_secondary_activity_until_outside_the_year_is_rejected(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->put(route('tax.update', 2026), [
            'expense_mode' => 'pausal',
            'pausal_percent' => 60,
            'children' => 0,
            'secondary_activity_until' => '2025-06-30',
        ])->assertSessionHasErrors('secondary_activity_until');
    }

    public function test_year_without_verified_parameters_is_rejected(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->put(route('tax.update', 2031), ['expense_mode' => 'pausal', 'children' => 0])
            ->assertNotFound();
    }

    public function test_profile_is_scoped_to_the_organization(): void
    {
        [$first, $firstOrg] = $this->createUserWithOrganization();
        [$second, $secondOrg] = $this->createUserWithOrganization();

        app(OrganizationContext::class)->forceSet($firstOrg);
        app(TaxYearReport::class)->profile($firstOrg, 2026)->update(['children' => 3]);

        app(OrganizationContext::class)->forceSet($secondOrg);
        $other = app(TaxYearReport::class)->profile($secondOrg, 2026);

        $this->assertSame(0, $other->children, 'Cizí organizace nesmí vidět nastavení jiné');
    }

}
