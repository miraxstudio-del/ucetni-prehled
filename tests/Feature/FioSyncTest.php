<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Bank\BankSyncService;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FioSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fioResponse(array $transactions): array
    {
        return [
            'accountStatement' => [
                'info' => ['accountId' => '2501234567', 'bankId' => '2010', 'currency' => 'CZK'],
                'transactionList' => ['transaction' => $transactions],
            ],
        ];
    }

    private function fioTransaction(int $id, string $date, float $amount, ?string $vs = null, ?string $name = null): array
    {
        return [
            'column22' => ['value' => $id, 'name' => 'ID pohybu'],
            'column0' => ['value' => $date.'+0200', 'name' => 'Datum'],
            'column1' => ['value' => $amount, 'name' => 'Objem'],
            'column14' => ['value' => 'CZK', 'name' => 'Měna'],
            'column2' => ['value' => '9876543210', 'name' => 'Protiúčet'],
            'column3' => ['value' => '0800', 'name' => 'Kód banky'],
            'column10' => ['value' => $name, 'name' => 'Název protiúčtu'],
            'column5' => ['value' => $vs, 'name' => 'VS'],
            'column16' => ['value' => 'Testovací platba', 'name' => 'Zpráva pro příjemce'],
        ];
    }

    private function setupConnection(): array
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        $connection = BankConnection::create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'provider' => 'fio',
            'api_token' => str_repeat('x', 64),
        ]);

        return [$user, $organization, $account, $connection];
    }

    public function test_sync_imports_transactions_and_deduplicates(): void
    {
        [, , , $connection] = $this->setupConnection();

        Http::fake([
            'fioapi.fio.cz/*' => Http::response($this->fioResponse([
                $this->fioTransaction(111, '2026-07-01', 1500.0, '123', 'Novák'),
                $this->fioTransaction(222, '2026-07-02', -500.0),
            ])),
        ]);

        $service = app(BankSyncService::class);

        $result = $service->sync($connection);
        $this->assertSame(2, $result['imported']);

        // druhý běh stejná data → nic nového
        $result = $service->sync($connection->fresh());
        $this->assertSame(0, $result['imported']);

        $this->assertSame(2, BankTransaction::count());
        $this->assertSame('1500.00', (string) BankTransaction::where('external_id', '111')->first()->amount);
        $this->assertSame('-500.00', (string) BankTransaction::where('external_id', '222')->first()->amount);
        $this->assertNotNull($connection->fresh()->last_sync_at);
    }

    public function test_sync_auto_matches_invoice_by_vs_and_amount(): void
    {
        [, $organization, , $connection] = $this->setupConnection();

        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Issued,
            'number' => '2026-0001',
            'variable_symbol' => '20260001',
            'total' => '1700.00',
        ]);

        Http::fake([
            'fioapi.fio.cz/*' => Http::response($this->fioResponse([
                $this->fioTransaction(333, '2026-07-10', 1700.0, '20260001', 'Klient'),
            ])),
        ]);

        $result = app(BankSyncService::class)->sync($connection);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_matches', [
            'invoice_id' => $invoice->id,
            'matched_by' => 'auto',
        ]);
    }

    public function test_sync_does_not_match_on_amount_mismatch(): void
    {
        [, $organization, , $connection] = $this->setupConnection();

        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Issued,
            'number' => '2026-0001',
            'variable_symbol' => '20260001',
            'total' => '1700.00',
        ]);

        Http::fake([
            'fioapi.fio.cz/*' => Http::response($this->fioResponse([
                $this->fioTransaction(444, '2026-07-10', 999.0, '20260001'),
            ])),
        ]);

        $result = app(BankSyncService::class)->sync($connection);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_failed_sync_marks_connection_with_error(): void
    {
        [, , , $connection] = $this->setupConnection();

        Http::fake(['fioapi.fio.cz/*' => Http::response(null, 409)]);

        try {
            app(BankSyncService::class)->sync($connection);
            $this->fail('Očekávána výjimka.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('error', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_error);
    }

    public function test_api_token_is_stored_encrypted(): void
    {
        [, , , $connection] = $this->setupConnection();

        $rawValue = DB::table('bank_connections')->where('id', $connection->id)->value('api_token');

        $this->assertStringNotContainsString(str_repeat('x', 64), $rawValue);
        $this->assertSame(str_repeat('x', 64), $connection->fresh()->api_token);
    }
}
