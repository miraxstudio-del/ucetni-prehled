<?php

namespace Tests\Feature;

use App\Enums\InvoiceTemplate;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Pdf\InvoicePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tři vzhledy faktury (klasik/moderní/minimal) a živý webový náhled.
 * Náhled i PDF sdílejí stejnou Blade šablonu (viz InvoicePdf::renderHtml) —
 * testuje se proto vždy skutečné PDF, ne jen náhled, ať se chyba v šabloně
 * chytí i v tom méně používaném z těch dvou.
 */
class InvoiceTemplatesAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'direction' => 'issued',
            'type' => 'invoice',
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'payment_method' => 'bank_transfer',
            'items' => [
                ['description' => 'Konzultace', 'quantity' => '2', 'unit' => 'h', 'unit_price' => '1500', 'vat_rate' => '21'],
            ],
            'action' => 'issue',
        ], $overrides);
    }

    /** @return iterable<string, array{InvoiceTemplate}> */
    public static function templates(): iterable
    {
        foreach (InvoiceTemplate::cases() as $template) {
            yield $template->value => [$template];
        }
    }

    #[DataProvider('templates')]
    public function test_pdf_renders_for_vat_payer_with_each_template(InvoiceTemplate $template): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $organization->update(['vat_payer' => true, 'invoice_template' => $template]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, [
            'bank_account_id' => $account->id,
            'note' => 'Poznámka k dokladu.',
        ]));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[DataProvider('templates')]
    public function test_pdf_renders_for_non_vat_payer_with_each_template(InvoiceTemplate $template): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $organization->update(['vat_payer' => false, 'invoice_template' => $template]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        // bez bankovního účtu — šablona musí zvládnout i chybějící QR
        $this->actingAs($user)->post('/faktury', $this->payload($client));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_web_preview_uses_the_same_template_as_the_pdf(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $organization->update(['invoice_template' => InvoiceTemplate::Moderni]);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Zákazník Testovací']);

        $this->actingAs($user)->post('/faktury', $this->payload($client));
        $invoice = Invoice::acrossAllOrganizations()->first();

        $pdfHtml = app(InvoicePdf::class)->renderHtml($invoice->fresh(['items', 'client', 'bankAccount', 'organization']));

        $this->assertStringContainsString('Zákazník Testovací', $pdfHtml);
        $this->assertStringContainsString('DODAVATEL', $pdfHtml, 'Moderní šablona má popisky velkými písmeny');
    }

    public function test_organization_preview_endpoint_reflects_unsaved_form_values(): void
    {
        [$user] = $this->createUserWithOrganization();

        $response = $this->actingAs($user)->post(route('settings.invoicing.preview'), [
            'name' => 'Nová Firma s.r.o. (ještě neuloženo)',
            'vat_payer' => '1',
            'invoice_template' => 'minimal',
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('Nová Firma s.r.o. (ještě neuloženo)');
        $response->assertSee('Ukázkový klient s.r.o.');
    }

    public function test_organization_preview_is_not_persisted(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $originalName = $organization->name;

        $this->actingAs($user)->post(route('settings.invoicing.preview'), [
            'name' => 'Tohle se nesmí uložit',
        ]);

        $this->assertSame($originalName, $organization->fresh()->name);
    }

    public function test_invoice_preview_endpoint_reflects_draft_items_and_client(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Rozpracovaný klient']);

        $response = $this->actingAs($user)->post(route('invoices.preview'), [
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => 'Testovací položka', 'quantity' => '3', 'unit' => 'ks', 'unit_price' => '500', 'vat_rate' => '21'],
            ],
        ]);

        $response->assertOk();
        $response->assertSee('Rozpracovaný klient');
        $response->assertSee('Testovací položka');

        // nic se neuložilo do evidence
        $this->assertSame(0, Invoice::count());
    }

    public function test_invoice_template_defaults_to_klasik(): void
    {
        [, $organization] = $this->createUserWithOrganization();

        $this->assertSame(InvoiceTemplate::Klasik, $organization->invoice_template);
    }
}
