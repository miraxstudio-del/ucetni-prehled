<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Exports\IsdocExporter;
use App\Services\Imports\IsdocImporter;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class AccountingExportTest extends TestCase
{
    use RefreshDatabase;

    private function setupData(Role $role = Role::Owner): array
    {
        [$user, $organization] = $this->createUserWithOrganization($role);
        app(OrganizationContext::class)->forceSet($organization);

        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Issued,
            'number' => '2026-0001',
            'variable_symbol' => '20260001',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'subtotal' => '1000.00',
            'vat_total' => '0.00',
            'total' => '1000.00',
        ]);

        $invoice->items()->create([
            'position' => 0,
            'description' => 'Testovací položka',
            'quantity' => '1.000',
            'unit' => 'ks',
            'unit_price' => '1000.00',
            'vat_rate' => '0.00',
            'line_subtotal' => '1000.00',
            'line_vat' => '0.00',
            'line_total' => '1000.00',
        ]);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-03-20',
            'amount' => '1000.00',
        ]);

        return [$user, $organization, $invoice];
    }

    public function test_zip_package_contains_expected_files(): void
    {
        [$user] = $this->setupData();

        $response = $this->actingAs($user)->get(route('accounting.export.zip', [
            'od' => '2026-01-01',
            'do' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertDownload('ucetni-prehled-export-2026-01-01-2026-12-31.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('faktury.csv', $entries);
        $this->assertContains('transakce.csv', $entries);
        $this->assertContains('prehled.xlsx', $entries);
        $this->assertContains('vydane/faktura-2026-0001.pdf', $entries);
        $this->assertContains('isdoc/2026-0001.isdoc', $entries);
        $this->assertTrue(
            collect($entries)->contains(fn ($e) => str_starts_with($e, 'transakce-') && str_ends_with($e, '.gpc')),
            'ZIP neobsahuje GPC výpis',
        );
    }

    public function test_invoices_csv_export_contains_invoice(): void
    {
        [$user] = $this->setupData(Role::Accountant); // účetní smí exportovat

        $response = $this->actingAs($user)->get(route('accounting.export.invoices-csv', [
            'od' => '2026-01-01',
            'do' => '2026-12-31',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('2026-0001', $response->getContent());
    }

    public function test_pohoda_xml_export_is_valid_xml(): void
    {
        [$user] = $this->setupData();

        $response = $this->actingAs($user)->get(route('accounting.export.pohoda', [
            'od' => '2026-01-01',
            'do' => '2026-12-31',
        ]));

        $response->assertOk();

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('2026-0001', $response->getContent());
        $this->assertStringContainsString('dataPack', $response->getContent());
    }

    public function test_isdoc_roundtrip_export_and_import(): void
    {
        [$user, , $invoice] = $this->setupData();

        // export z detailu faktury
        $response = $this->actingAs($user)->get(route('invoices.isdoc', $invoice));
        $response->assertOk();

        $isdocXml = $response->getContent();
        $this->assertStringContainsString('2026-0001', $isdocXml);

        // import do jiné organizace jako přijatá faktura
        [, $otherOrganization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($otherOrganization);

        $imported = app(IsdocImporter::class)->import($isdocXml);

        $this->assertSame('received', $imported->direction->value);
        $this->assertSame('2026-0001', $imported->number);
        $this->assertSame('1000.00', (string) $imported->total);
        $this->assertCount(1, $imported->items);
        $this->assertSame($otherOrganization->id, $imported->organization_id);
    }

    public function test_isdoc_export_produces_valid_xml_structure(): void
    {
        [, , $invoice] = $this->setupData();

        $xml = simplexml_load_string(app(IsdocExporter::class)->export($invoice));

        $this->assertNotFalse($xml);
        $this->assertSame('2026-0001', (string) $xml->ID);
        $this->assertSame('1000.00', (string) $xml->LegalMonetaryTotal->TaxInclusiveAmount);
    }
}
