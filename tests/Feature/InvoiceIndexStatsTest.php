<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Statistické karty na přehledu faktur — rozpad podle stavu nezávislý na filtru Stav. */
class InvoiceIndexStatsTest extends TestCase
{
    use RefreshDatabase;

    private function seedInvoices(): array
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        // po splatnosti
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Issued, 'due_date' => now()->subDays(5), 'total' => 1000,
        ]);
        // neuhrazená, splatnost v budoucnu
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Sent, 'due_date' => now()->addDays(5), 'total' => 2000,
        ]);
        // zaplacená
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Paid, 'due_date' => now()->subDays(2), 'total' => 3000,
        ]);
        // stornovaná — nesmí se počítat nikam
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Cancelled, 'due_date' => now()->subDays(2), 'total' => 9999,
        ]);
        // koncept — také se nepočítá do žádné ze 4 karet
        Invoice::factory()->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Draft, 'due_date' => now()->addDays(1), 'total' => 500,
        ]);

        return [$user, $organization];
    }

    public function test_stat_cards_show_correct_breakdown(): void
    {
        [$user] = $this->seedInvoices();

        $response = $this->actingAs($user)->get(route('invoices.index'));

        $response->assertOk();
        // celkem počítá jako stávající "Součet za výběr" — vyloučí jen storno,
        // koncept (500 Kč) se počítá stejně jako dřív
        $response->assertSee('6 500,00 Kč');
        $response->assertSee('1 000,00 Kč'); // po splatnosti
        $response->assertSee('2 000,00 Kč'); // neuhrazené
        $response->assertSee('3 000,00 Kč'); // zaplaceno
    }

    public function test_stat_cards_stay_the_same_when_status_filter_is_applied(): void
    {
        [$user] = $this->seedInvoices();

        // filtr na "Zaplacená" ukáže v tabulce jen 1 fakturu, ale karty
        // musí pořád ukazovat celý rozpad, ne jen ten jeden vyfiltrovaný stav
        $response = $this->actingAs($user)->get(route('invoices.index', ['stav' => 'paid']));

        $response->assertOk();
        $response->assertSee('1 000,00 Kč'); // po splatnosti pořád vidět
        $response->assertSee('2 000,00 Kč'); // neuhrazené pořád vidět
    }

    public function test_reset_link_appears_only_with_active_filters(): void
    {
        [$user] = $this->seedInvoices();

        $this->actingAs($user)->get(route('invoices.index'))
            ->assertDontSee('Resetovat');

        $this->actingAs($user)->get(route('invoices.index', ['q' => 'test']))
            ->assertSee('Resetovat');
    }

    public function test_per_page_selector_changes_page_size(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        Invoice::factory()->count(15)->create([
            'organization_id' => $organization->id, 'client_id' => $client->id,
            'status' => InvoiceStatus::Issued,
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index', ['na_stranku' => 10]));

        $response->assertOk();
        $this->assertCount(10, $response->viewData('invoices')->items());
    }

    public function test_invalid_per_page_falls_back_to_default(): void
    {
        [$user] = $this->createUserWithOrganization();

        $response = $this->actingAs($user)->get(route('invoices.index', ['na_stranku' => 999]));

        $response->assertOk();
        $this->assertSame(25, $response->viewData('perPage'));
    }
}
