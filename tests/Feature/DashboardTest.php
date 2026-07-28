<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_no_data(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rychlé akce')
            ->assertSee('Nová faktura')
            ->assertSee('Nový klient')
            ->assertSee('Přijatá platba')
            ->assertSee('Výdaj')
            ->assertSee('beze změny');
    }

    public function test_dashboard_shows_real_month_over_month_trend(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        // minulý měsíc 1000 Kč, tento měsíc 2000 Kč → +100 %
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Issued, 'issue_date' => now()->subMonthNoOverflow(), 'total' => 1000,
        ]);
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Issued, 'issue_date' => now(), 'total' => 2000,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('100')
            ->assertSee('vs. minulý měsíc');
    }

    public function test_dashboard_year_selector_switches_chart_year(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Paid, 'paid_at' => '2024-03-15', 'issue_date' => '2024-03-10', 'total' => 5000,
        ]);

        $this->actingAs($user)->get(route('dashboard', ['rok' => 2024]))
            ->assertOk()
            ->assertSee('5k');
    }

    public function test_trend_is_omitted_when_previous_period_is_zero(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        // jen tento měsíc, minulý měsíc 0 → procento by bylo nekonečno, musí se skrýt
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Issued, 'issue_date' => now(), 'total' => 3000,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('vs. minulý měsíc');
    }
}
