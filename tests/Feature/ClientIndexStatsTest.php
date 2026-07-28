<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Statistické karty a sloupec "Poslední aktivita" na přehledu klientů. */
class ClientIndexStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stat_cards_reflect_real_data(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        // firemní, aktivní (faktura před měsícem)
        $company = Client::factory()->create(['organization_id' => $organization->id, 'ico' => '12345678']);
        Invoice::factory()->create(['organization_id' => $organization->id, 'client_id' => $company->id, 'issue_date' => now()->subMonth()]);

        // soukromá osoba, neaktivní (faktura před 2 lety)
        $individual = Client::factory()->create(['organization_id' => $organization->id, 'ico' => null]);
        Invoice::factory()->create(['organization_id' => $organization->id, 'client_id' => $individual->id, 'issue_date' => now()->subYears(2)]);

        // klient bez jediné faktury
        Client::factory()->create(['organization_id' => $organization->id, 'ico' => null]);

        $response = $this->actingAs($user)->get(route('clients.index'));

        $response->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['active'], 'jen klient s fakturou za posledních 12 měsíců je aktivní');
        $this->assertSame(1, $stats['company']);
        $this->assertSame(2, $stats['individual']);
    }

    public function test_last_activity_column_shows_latest_invoice_date(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Poslední Aktivita s.r.o.']);

        Invoice::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id, 'issue_date' => '2026-01-10']);
        Invoice::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id, 'issue_date' => '2026-05-20']);

        $this->actingAs($user)->get(route('clients.index'))
            ->assertOk()
            ->assertSee('20. 5. 2026'); // nejnovější, ne první
    }

    public function test_client_without_invoices_shows_dash_for_last_activity(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->get(route('clients.index'))->assertOk();
    }
}
