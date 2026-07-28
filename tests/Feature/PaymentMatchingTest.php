<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMatch;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PaymentMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrg(Role $role = Role::Owner): array
    {
        [$user, $organization] = $this->createUserWithOrganization($role);
        app(OrganizationContext::class)->forceSet($organization);

        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Issued,
            'number' => '2026-0001',
            'variable_symbol' => '20260001',
            'total' => '1700.00',
        ]);

        return [$user, $organization, $account, $invoice];
    }

    public function test_manual_match_marks_invoice_paid(): void
    {
        [$user, $organization, $account, $invoice] = $this->setupOrg();

        $transaction = BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'amount' => '1700.00',
            'variable_symbol' => null,
        ]);

        $this->actingAs($user)
            ->post(route('bank.match', $transaction), ['invoice_id' => $invoice->id])
            ->assertRedirect();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_matches', [
            'invoice_id' => $invoice->id,
            'bank_transaction_id' => $transaction->id,
            'matched_by' => 'manual',
            'matched_by_user_id' => $user->id,
        ]);
    }

    public function test_unmatch_reverts_invoice_to_issued(): void
    {
        [$user, $organization, $account, $invoice] = $this->setupOrg();

        $transaction = BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'amount' => '1700.00',
        ]);

        $this->actingAs($user)->post(route('bank.match', $transaction), ['invoice_id' => $invoice->id]);

        $match = PaymentMatch::acrossAllOrganizations()->firstOrFail();

        $this->actingAs($user)->delete(route('bank.unmatch', $match))->assertRedirect();

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
        $this->assertSame(0, PaymentMatch::acrossAllOrganizations()->count());
    }

    public function test_cannot_match_foreign_transaction_or_invoice(): void
    {
        [$user] = $this->setupOrg();

        // cizí organizace s transakcí i fakturou
        [, $foreignOrg] = $this->createUserWithOrganization();
        $foreignAccount = BankAccount::factory()->create(['organization_id' => $foreignOrg->id]);
        $foreignTransaction = BankTransaction::factory()->create([
            'organization_id' => $foreignOrg->id,
            'bank_account_id' => $foreignAccount->id,
        ]);

        $this->actingAs($user)
            ->post(route('bank.match', $foreignTransaction), ['invoice_id' => 1])
            ->assertNotFound();
    }

    public function test_accountant_can_view_but_not_manage_bank(): void
    {
        [$user, $organization, $account] = $this->setupOrg(Role::Accountant);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
        ]);

        $this->actingAs($user)->get(route('bank.index'))->assertOk();

        $this->actingAs($user)->post(route('bank.sync'))->assertForbidden();

        $this->actingAs($user)->post(route('bank.import'), [
            'bank_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('vypis.gpc', 'obsah'),
        ])->assertForbidden();
    }

    public function test_gpc_import_endpoint_imports_and_deduplicates(): void
    {
        [$user, $organization, $account, $invoice] = $this->setupOrg();

        // platba přesně odpovídající faktuře (VS + částka) → auto spárování
        $line = '075'
            .str_pad($account->account_number, 16, '0', STR_PAD_LEFT)
            .str_pad('9876543210', 16, '0', STR_PAD_LEFT)
            .str_pad('1', 13, '0', STR_PAD_LEFT)
            .str_pad('170000', 12, '0', STR_PAD_LEFT)
            .'2'
            .str_pad('20260001', 10, '0', STR_PAD_LEFT)
            .'0800'.'0308'
            .str_pad('0', 10, '0', STR_PAD_LEFT)
            .'100726'
            .str_pad('KLIENT', 20);

        $file = UploadedFile::fake()->createWithContent('vypis.gpc', $line."\r\n");

        $this->actingAs($user)->post(route('bank.import'), [
            'bank_account_id' => $account->id,
            'file' => $file,
        ])->assertRedirect();

        $this->assertSame(1, BankTransaction::count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // opakovaný import stejného souboru → žádná duplicita
        $this->actingAs($user)->post(route('bank.import'), [
            'bank_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('vypis.gpc', $line."\r\n"),
        ]);

        $this->assertSame(1, BankTransaction::count());
    }
}
