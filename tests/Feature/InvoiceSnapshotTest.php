<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Exports\IsdocExporter;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\SpdPayment;
use App\Services\Pdf\InvoicePdf;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Neměnnost vystavené faktury: údaje dodavatele, odběratele a účtu se při
 * vystavení zmrazí. Pozdější přejmenování klienta nebo změna adresy
 * organizace už nesmí změnit PDF/ISDOC dokladu — je to právní dokument.
 */
class InvoiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Organization, 2: Client, 3: Invoice} */
    private function issuedInvoice(): array
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $organization->update(['name' => 'Původní dodavatel', 'street' => 'Stará 1', 'city' => 'Brno']);

        $client = Client::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Původní klient s.r.o.',
            'street' => 'Dlouhá 42',
            'city' => 'Praha',
            'ico' => '12345678',
        ]);
        BankAccount::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', [
            'direction' => 'issued',
            'type' => 'invoice',
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'payment_method' => 'bank_transfer',
            'bank_account_id' => BankAccount::first()->id,
            'items' => [
                ['description' => 'Práce', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '1000', 'vat_rate' => '0'],
            ],
            'action' => 'issue',
        ]);

        return [$user, $organization, $client, Invoice::acrossAllOrganizations()->firstOrFail()];
    }

    public function test_issue_takes_a_snapshot(): void
    {
        [, , , $invoice] = $this->issuedInvoice();

        $this->assertNotNull($invoice->snapshot);
        $this->assertSame('Původní klient s.r.o.', $invoice->snapshot['client']['name']);
        $this->assertSame('Původní dodavatel', $invoice->snapshot['organization']['name']);
        $this->assertNotNull($invoice->snapshot['bank_account']['account_number']);
        $this->assertNotNull($invoice->snapshot['taken_at']);
    }

    public function test_snapshot_is_encrypted_in_the_database(): void
    {
        [, , , $invoice] = $this->issuedInvoice();

        $raw = DB::table('invoices')->where('id', $invoice->id)->value('snapshot');

        $this->assertStringStartsWith('v1.', (string) $raw, 'Snapshot musí být v DB šifrovaný');
        $this->assertStringNotContainsString('Původní klient', (string) $raw);
    }

    public function test_pdf_shows_frozen_data_after_client_rename(): void
    {
        [, , $client, $invoice] = $this->issuedInvoice();

        $client->update(['name' => 'PŘEJMENOVANÝ klient a.s.', 'city' => 'Ostrava']);

        $html = app(InvoicePdf::class)->renderHtml($invoice->fresh());

        $this->assertStringContainsString('Původní klient s.r.o.', $html);
        $this->assertStringContainsString('Praha', $html);
        $this->assertStringNotContainsString('PŘEJMENOVANÝ', $html);
        $this->assertStringNotContainsString('Ostrava', $html);
    }

    public function test_pdf_shows_frozen_supplier_after_organization_change(): void
    {
        [, $organization, , $invoice] = $this->issuedInvoice();

        $organization->update(['name' => 'Nový název dodavatele', 'street' => 'Nová 99']);

        $html = app(InvoicePdf::class)->renderHtml($invoice->fresh());

        $this->assertStringContainsString('Původní dodavatel', $html);
        $this->assertStringContainsString('Stará 1', $html);
        $this->assertStringNotContainsString('Nový název dodavatele', $html);
    }

    public function test_isdoc_uses_frozen_data_too(): void
    {
        [, , $client, $invoice] = $this->issuedInvoice();

        $client->update(['name' => 'PŘEJMENOVANÝ klient a.s.']);

        $xml = app(IsdocExporter::class)->export($invoice->fresh());

        $this->assertStringContainsString('Původní klient s.r.o.', $xml);
        $this->assertStringNotContainsString('PŘEJMENOVANÝ', $xml);
    }

    public function test_draft_stays_live(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Klient konceptu']);

        $draft = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $this->assertNull($draft->snapshot);

        $client->update(['name' => 'Nové jméno u konceptu']);

        $html = app(InvoicePdf::class)->renderHtml($draft->fresh());

        $this->assertStringContainsString('Nové jméno u konceptu', $html, 'Koncept musí zůstat živý');
    }

    public function test_duplicate_does_not_inherit_the_snapshot(): void
    {
        [, , , $invoice] = $this->issuedInvoice();

        $copy = app(InvoiceService::class)->duplicate($invoice);

        $this->assertNull($copy->snapshot, 'Kopie je nový koncept — zmrazená data originálu se nedědí');
        $this->assertSame(InvoiceStatus::Draft, $copy->status);
    }

    public function test_backfill_command_freezes_existing_invoices(): void
    {
        [, $organization, , $invoice] = $this->issuedInvoice();

        // simulace dokladu z doby před zavedením snapshotů
        DB::table('invoices')->where('id', $invoice->id)->update(['snapshot' => null]);

        $draft = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $invoice->client_id,
            'status' => InvoiceStatus::Draft,
        ]);

        $this->artisan('ucetni-prehled:snapshot-invoices')->assertSuccessful();

        $this->assertNotNull($invoice->fresh()->snapshot);
        $this->assertSame('Původní klient s.r.o.', $invoice->fresh()->snapshot['client']['name']);
        $this->assertNull($draft->fresh()->snapshot, 'Koncepty se nezmrazují');
    }

    public function test_backfill_does_not_overwrite_existing_snapshots(): void
    {
        [, , $client, $invoice] = $this->issuedInvoice();

        $client->update(['name' => 'Jméno po vystavení']);

        $this->artisan('ucetni-prehled:snapshot-invoices')->assertSuccessful();

        $this->assertSame(
            'Původní klient s.r.o.',
            $invoice->fresh()->snapshot['client']['name'],
            'Existující snapshot se nikdy nepřepisuje',
        );
    }

    public function test_qr_payment_uses_frozen_bank_account(): void
    {
        [, , , $invoice] = $this->issuedInvoice();

        $frozenIban = $invoice->snapshot['bank_account']['iban'];

        // účet se po vystavení změní (jiný IBAN)
        $invoice->bankAccount->update(['iban' => 'CZ9999999999999999999999']);

        $fresh = $invoice->fresh();
        $fresh->freezeForDocument();
        $spd = app(SpdPayment::class)->build($fresh);

        $this->assertStringContainsString('ACC:'.$frozenIban, (string) $spd);
        $this->assertStringNotContainsString('CZ9999999999999999999999', (string) $spd);
    }
}
