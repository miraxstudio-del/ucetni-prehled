<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ověření stavů „zaplaceno“ proti bance: import označil faktury zaplacené
 * paušálně — příkaz ucetni-prehled:verify-payments domněnku nahradí důkazem z banky.
 */
class VerifyPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 7, 19, 12));
    }

    private function importedInvoice(array $overrides = []): array
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        $invoice = Invoice::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'bank_account_id' => $account->id,
            'status' => InvoiceStatus::Paid,           // jak to dělal starý import
            'number' => '2026013',
            'variable_symbol' => '2026013',
            'issue_date' => '2026-07-13',
            'due_date' => '2026-07-27',
            'total' => 2000,
            'subtotal' => 2000,
            'note' => 'Importováno z 2026013.pdf',
        ], $overrides));

        // paid_at nechal import prázdné — to je poznávací znak domněnky
        $this->assertNull($invoice->paid_at);

        return [$organization, $account, $invoice];
    }

    public function test_unverified_import_paid_is_downgraded_to_issued(): void
    {
        [, , $invoice] = $this->importedInvoice();

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status, 'Bez platby v bance není „zaplaceno“');
    }

    public function test_invoice_with_matching_bank_payment_stays_paid_with_evidence(): void
    {
        [$organization, $account, $invoice] = $this->importedInvoice();

        $transaction = BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-15',
            'amount' => 2000,
            'currency' => 'CZK',
            'variable_symbol' => '2026013',
        ]);

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('2026-07-15', $fresh->paid_at->toDateString(), 'Datum úhrady z banky');
        $this->assertDatabaseHas('payment_matches', [
            'invoice_id' => $invoice->id,
            'bank_transaction_id' => $transaction->id,
        ]);
    }

    public function test_aukro_payment_matches_by_buyer_name_despite_netto_amount(): void
    {
        [$organization, $account, $invoice] = $this->importedInvoice([
            'note' => 'Importováno z 2026099 - AUKRO - pepa123.pdf',
            'number' => '2026099',
            'variable_symbol' => '2026099',
            'total' => 1970,
            'subtotal' => 1970,
        ]);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-16',
            'amount' => 1772.70, // netto po odečtení provize Aukra
            'currency' => 'CZK',
            'variable_symbol' => '7160777', // VS je číslo nabídky, ne faktury
            'message' => 'Nabídka 7160777 od kupujícího pepa123',
        ]);

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('2026-07-16', $fresh->paid_at->toDateString());
    }

    public function test_payment_without_vs_matches_by_counterparty_name_and_exact_amount(): void
    {
        [$organization, $account, $invoice] = $this->importedInvoice();

        // klient „Denisa Golíková“, banka posílá „GOLÍKOVÁ DENISA“ bez VS
        $invoice->client->update(['name' => 'Denisa Golíková']);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-16',
            'amount' => 2000,
            'currency' => 'CZK',
            'variable_symbol' => null,
            'counterparty_name' => 'GOLÍKOVÁ DENISA',
        ]);

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame('2026-07-16', $fresh->paid_at->toDateString());
    }

    public function test_name_match_requires_blank_vs_and_exact_amount(): void
    {
        [$organization, $account, $invoice] = $this->importedInvoice();
        $invoice->client->update(['name' => 'Denisa Golíková']);

        // stejné jméno, ale VS patří jiné faktuře → nesmí se ukrást
        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-16',
            'amount' => 2000,
            'currency' => 'CZK',
            'variable_symbol' => '2026099',
            'counterparty_name' => 'GOLÍKOVÁ DENISA',
        ]);

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status, 'Platba s cizím VS se nepoužije');
    }

    public function test_manually_paid_invoice_is_left_alone(): void
    {
        [, , $invoice] = $this->importedInvoice(['note' => null]);

        // uživatel označil ručně — má datum úhrady
        $invoice->forceFill(['paid_at' => '2026-07-14'])->save();

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status, 'Vědomé rozhodnutí uživatele se nemění');
    }

    public function test_closed_year_unverified_stays_paid(): void
    {
        [, , $invoice] = $this->importedInvoice([
            'number' => '2025010',
            'variable_symbol' => '2025010',
            'issue_date' => '2025-06-01',
            'due_date' => '2025-06-15',
            'note' => 'Importováno z 2025010.pdf',
        ]);

        $this->artisan('ucetni-prehled:verify-payments', ['--year' => 2025])->assertSuccessful();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status, 'Uzavřený rok drží přiznání, ne banka');
    }

    public function test_dry_run_changes_nothing(): void
    {
        [, , $invoice] = $this->importedInvoice();

        $this->artisan('ucetni-prehled:verify-payments', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_matches', 0);
    }

    public function test_owner_can_trigger_verification_from_ui(): void
    {
        [$organization, $account, $invoice] = $this->importedInvoice();
        $user = $organization->memberships()->first()->user;

        $transaction = BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-15',
            'amount' => 2000,
            'currency' => 'CZK',
            'variable_symbol' => '2026013',
        ]);

        $this->actingAs($user)
            ->post(route('bank.verify-payments'), ['rok' => 2026])
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertDatabaseHas('payment_matches', [
            'invoice_id' => $invoice->id,
            'bank_transaction_id' => $transaction->id,
        ]);
    }

    public function test_accountant_cannot_trigger_verification_from_ui(): void
    {
        [$user, $organization] = $this->createUserWithOrganization(Role::Accountant);
        app(OrganizationContext::class)->forceSet($organization);

        $this->actingAs($user)
            ->post(route('bank.verify-payments'))
            ->assertForbidden();
    }

    public function test_one_payment_cannot_confirm_two_invoices(): void
    {
        [$organization, $account, $first] = $this->importedInvoice();

        $second = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $first->client_id,
            'bank_account_id' => $account->id,
            'status' => InvoiceStatus::Paid,
            'number' => '2026014',
            'variable_symbol' => '2026013', // stejný VS (překlep) — stejná částka
            'issue_date' => '2026-07-14',
            'due_date' => '2026-07-28',
            'total' => 2000,
            'subtotal' => 2000,
            'note' => 'Importováno z 2026014.pdf',
        ]);

        BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
            'booked_on' => '2026-07-15',
            'amount' => 2000,
            'currency' => 'CZK',
            'variable_symbol' => '2026013',
        ]);

        $this->artisan('ucetni-prehled:verify-payments')->assertSuccessful();

        $paidCount = collect([$first->fresh(), $second->fresh()])
            ->filter(fn ($i) => $i->status === InvoiceStatus::Paid)
            ->count();

        $this->assertSame(1, $paidCount, 'Jedna platba smí doložit jen jednu fakturu');
        $this->assertDatabaseCount('payment_matches', 1);
    }
}
