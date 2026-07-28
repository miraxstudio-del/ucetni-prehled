<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Globální vyhledávání v horní liště — faktury podle čísla/VS (SQL),
 * klienti podle názvu/IČO (šifrované sloupce, srovnání po dešifrování).
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function setUpOrganization(): array
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        return [$user, $organization];
    }

    public function test_finds_invoice_by_number(): void
    {
        [$user, $organization] = $this->setUpOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Test s.r.o.']);
        Invoice::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id, 'number' => '2026042']);

        $response = $this->actingAs($user)->getJson(route('search', ['q' => '2026042']));

        $response->assertOk()
            ->assertJsonPath('invoices.0.label', '2026042')
            ->assertJsonPath('invoices.0.sub', 'Test s.r.o.')
            ->assertJsonCount(0, 'clients');
    }

    public function test_finds_invoice_by_variable_symbol(): void
    {
        [$user, $organization] = $this->setUpOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'number' => '2026050',
            'variable_symbol' => '999888',
        ]);

        $this->actingAs($user)->getJson(route('search', ['q' => '999888']))
            ->assertOk()
            ->assertJsonPath('invoices.0.label', '2026050');
    }

    public function test_finds_client_by_name_despite_encryption(): void
    {
        [$user, $organization] = $this->setUpOrganization();
        Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Denisa Golíková']);

        $this->actingAs($user)->getJson(route('search', ['q' => 'golíkov']))
            ->assertOk()
            ->assertJsonPath('clients.0.label', 'Denisa Golíková')
            ->assertJsonCount(0, 'invoices');
    }

    public function test_finds_client_by_ico(): void
    {
        [$user, $organization] = $this->setUpOrganization();
        Client::factory()->create(['organization_id' => $organization->id, 'name' => 'AGRO Brno', 'ico' => '12345678']);

        $this->actingAs($user)->getJson(route('search', ['q' => '12345678']))
            ->assertOk()
            ->assertJsonPath('clients.0.label', 'AGRO Brno');
    }

    public function test_short_query_returns_empty_without_error(): void
    {
        [$user] = $this->setUpOrganization();

        $this->actingAs($user)->getJson(route('search', ['q' => 'a']))
            ->assertOk()
            ->assertJsonCount(0, 'invoices')
            ->assertJsonCount(0, 'clients');
    }

    public function test_results_are_scoped_to_the_current_organization(): void
    {
        [$user, $organization] = $this->setUpOrganization();
        Client::factory()->create(['organization_id' => $organization->id]);

        [, $otherOrganization] = $this->createUserWithOrganization();
        Client::factory()->create(['organization_id' => $otherOrganization->id, 'name' => 'Cizí firma s.r.o.']);
        Invoice::factory()->create([
            'organization_id' => $otherOrganization->id,
            'client_id' => Client::factory()->create(['organization_id' => $otherOrganization->id])->id,
            'number' => '2099001',
        ]);

        $this->actingAs($user)->getJson(route('search', ['q' => 'cizí']))
            ->assertOk()
            ->assertJsonCount(0, 'clients');

        $this->actingAs($user)->getJson(route('search', ['q' => '2099001']))
            ->assertOk()
            ->assertJsonCount(0, 'invoices');
    }

}
