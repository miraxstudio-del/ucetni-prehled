<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Smoke testy vykreslení stránek modulu Faktury a Klienti. */
class InvoicePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_invoice_and_client_pages_render(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        BankAccount::factory()->create(['organization_id' => $organization->id]);

        // vytvořit a vystavit fakturu přes aplikaci
        $this->actingAs($user)->post('/faktury', [
            'direction' => 'issued',
            'type' => 'invoice',
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'payment_method' => 'bank_transfer',
            'items' => [
                ['description' => 'Test', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '1000', 'vat_rate' => '0'],
            ],
            'action' => 'issue',
        ]);

        $invoice = Invoice::acrossAllOrganizations()->first();
        $draft = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        $this->actingAs($user)->get(route('invoices.create'))->assertOk();
        $this->actingAs($user)->get(route('invoices.show', $invoice))->assertOk()->assertSee('Test');
        $this->actingAs($user)->get(route('invoices.edit', $draft))->assertOk();
        $this->actingAs($user)->get(route('clients.index'))->assertOk()->assertSee($client->name);
        $this->actingAs($user)->get(route('clients.create'))->assertOk();
        $this->actingAs($user)->get(route('clients.edit', $client))->assertOk();
        $this->actingAs($user)->get(route('settings.invoicing'))->assertOk();
    }
}
