<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_be_created(): void
    {
        [$user] = $this->createUserWithOrganization();

        $response = $this->actingAs($user)->post('/klienti', [
            'name' => 'Acme s.r.o.',
            'ico' => '12345678',
            'email' => 'info@acme.cz',
        ]);

        $client = Client::acrossAllOrganizations()->whereName('Acme s.r.o.')->first();

        $this->assertNotNull($client);
        $response->assertRedirect(route('clients.show', $client));

        $this->assertDatabaseHas('audit_logs', ['action' => 'client.created', 'entity_id' => $client->id]);
    }

    public function test_client_belongs_to_current_organization_automatically(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();

        $this->actingAs($user)->post('/klienti', ['name' => 'Acme s.r.o.']);

        // jméno je v DB zašifrované → ověřujeme přes model (slepý index)
        $client = Client::acrossAllOrganizations()->whereName('Acme s.r.o.')->firstOrFail();
        $this->assertSame($organization->id, $client->organization_id);
    }

    public function test_foreign_client_is_not_accessible(): void
    {
        [$user] = $this->createUserWithOrganization();
        [, $foreignOrg] = $this->createUserWithOrganization();

        $foreignClient = Client::factory()->create(['organization_id' => $foreignOrg->id]);

        $this->actingAs($user)->get(route('clients.show', $foreignClient))->assertNotFound();
        $this->actingAs($user)->put(route('clients.update', $foreignClient), ['name' => 'Hack'])->assertNotFound();
        $this->actingAs($user)->delete(route('clients.destroy', $foreignClient))->assertNotFound();
    }

    public function test_accountant_cannot_create_clients(): void
    {
        [$user] = $this->createUserWithOrganization(Role::Accountant);

        $this->actingAs($user)->post('/klienti', ['name' => 'Acme'])->assertForbidden();
    }

    public function test_accountant_can_view_clients(): void
    {
        [$user, $organization] = $this->createUserWithOrganization(Role::Accountant);

        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();
    }

    public function test_ares_lookup_returns_mapped_data(): void
    {
        [$user] = $this->createUserWithOrganization();

        Http::fake([
            'ares.gov.cz/*' => Http::response([
                'ico' => '12345678',
                'obchodniJmeno' => 'Testovací firma s.r.o.',
                'dic' => 'CZ12345678',
                'sidlo' => [
                    'nazevUlice' => 'Dlouhá',
                    'cisloDomovni' => 12,
                    'cisloOrientacni' => 3,
                    'nazevObce' => 'Praha',
                    'psc' => 11000,
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson('/ares/12345678')
            ->assertOk()
            ->assertJson([
                'name' => 'Testovací firma s.r.o.',
                'dic' => 'CZ12345678',
                'street' => 'Dlouhá 12/3',
                'city' => 'Praha',
                'zip' => '11000',
            ]);
    }

    public function test_ares_lookup_handles_unknown_ico(): void
    {
        [$user] = $this->createUserWithOrganization();

        Http::fake(['ares.gov.cz/*' => Http::response(null, 404)]);

        $this->actingAs($user)->getJson('/ares/99999999')->assertNotFound();
    }
}
