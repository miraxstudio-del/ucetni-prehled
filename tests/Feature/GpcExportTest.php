<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Bank\GpcExporter;
use App\Services\Bank\GpcParser;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpcExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_produces_valid_gpc_parsable_back(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-03-10',
            'amount' => '1500.00',
            'variable_symbol' => '20260005',
            'counterparty_account' => '1234567890/0800',
            'counterparty_name' => 'Novák Jan',
        ]);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-03-15',
            'amount' => '-250.50',
            'variable_symbol' => null,
            'counterparty_account' => null,
        ]);

        $content = app(GpcExporter::class)->export(
            $account,
            BankTransaction::all(),
            '2026-01-01',
            '2026-12-31',
        );

        // výstup je v CP1250 s CRLF
        $this->assertStringContainsString("\r\n", $content);

        // a náš parser jej umí přečíst zpět (roundtrip)
        $parsed = app(GpcParser::class)->parse($content);

        $this->assertCount(2, $parsed['transactions']);
        $this->assertSame('1500.00', $parsed['transactions'][0]->amount);
        $this->assertSame('20260005', $parsed['transactions'][0]->variableSymbol);
        $this->assertSame('-250.50', $parsed['transactions'][1]->amount);
        $this->assertSame('2026-03-10', $parsed['transactions'][0]->bookedOn);
    }

    public function test_export_endpoint_downloads_file_and_accountant_is_allowed(): void
    {
        [$user, $organization] = $this->createUserWithOrganization(Role::Accountant);
        app(OrganizationContext::class)->forceSet($organization);

        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);
        BankTransaction::factory()->count(2)->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-02-01',
        ]);

        $response = $this->actingAs($user)->get(route('bank.export', [
            'bank_account_id' => $account->id,
            'od' => '2026-01-01',
            'do' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="vypis-2026-01-01-2026-12-31.gpc"');
    }
}
