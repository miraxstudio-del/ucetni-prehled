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
use Tests\TestCase;

/**
 * Trvalé smazání i vystavené/zaplacené faktury — obchází storno, proto jen
 * vlastník a jen s potvrzením opsáním čísla dokladu.
 */
class InvoiceForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function issuedInvoice(Role $role = Role::Owner): array
    {
        [$user, $organization] = $this->createUserWithOrganization($role);
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Paid,
            'number' => '2026013',
            'total' => 2000,
        ]);

        return [$user, $organization, $invoice];
    }

    public function test_owner_can_force_delete_an_issued_invoice(): void
    {
        [$user, , $invoice] = $this->issuedInvoice();

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026013',
        ])->assertRedirect(route('invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_wrong_confirmation_number_is_rejected(): void
    {
        [$user, , $invoice] = $this->issuedInvoice();

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026099',
        ])->assertSessionHasErrors('confirm_number');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_missing_confirmation_is_rejected(): void
    {
        [$user, , $invoice] = $this->issuedInvoice();

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice))
            ->assertSessionHasErrors('confirm_number');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_member_cannot_force_delete(): void
    {
        [$user, , $invoice] = $this->issuedInvoice(Role::Member);

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026013',
        ])->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_accountant_cannot_force_delete(): void
    {
        [$user, , $invoice] = $this->issuedInvoice(Role::Accountant);

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026013',
        ])->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_force_delete_removes_items_and_payment_matches_too(): void
    {
        [$user, $organization, $invoice] = $this->issuedInvoice();
        $invoice->items()->create([
            'position' => 0, 'description' => 'Práce', 'quantity' => 1, 'unit' => 'ks',
            'unit_price' => 2000, 'vat_rate' => 0, 'line_subtotal' => 2000, 'line_vat' => 0, 'line_total' => 2000,
        ]);
        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);
        $transaction = BankTransaction::factory()->create([
            'organization_id' => $organization->id,
            'bank_account_id' => $account->id,
        ]);
        PaymentMatch::create([
            'organization_id' => $organization->id,
            'invoice_id' => $invoice->id,
            'bank_transaction_id' => $transaction->id,
            'amount' => 2000,
            'matched_by' => 'manual',
        ]);

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026013',
        ]);

        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('payment_matches', ['invoice_id' => $invoice->id]);
        // transakce samotná zůstává — jen ztratila spárování
        $this->assertDatabaseHas('bank_transactions', ['id' => $transaction->id]);
    }

    public function test_force_delete_is_audit_logged_with_full_identification(): void
    {
        [$user, , $invoice] = $this->issuedInvoice();

        $this->actingAs($user)->delete(route('invoices.force-destroy', $invoice), [
            'confirm_number' => '2026013',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.force_deleted',
            'entity_id' => $invoice->id,
        ]);
    }

    public function test_draft_can_also_be_force_deleted_by_owner(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $draft = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'number' => null,
        ]);

        $this->actingAs($user)->delete(route('invoices.force-destroy', $draft), [
            'confirm_number' => 'KONCEPT',
        ])->assertRedirect(route('invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $draft->id]);
    }

    public function test_regular_soft_delete_still_works_unchanged_for_drafts(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $draft = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $this->actingAs($user)->delete(route('invoices.destroy', $draft))
            ->assertRedirect(route('invoices.index'));

        // soft delete: v DB pořád je, jen skrytý přes global scope
        $this->assertSoftDeleted('invoices', ['id' => $draft->id]);
    }

    public function test_index_shows_delete_button_only_to_owner(): void
    {
        [$owner, , $invoice] = $this->issuedInvoice();

        $this->actingAs($owner)->get(route('invoices.index'))
            ->assertOk()
            ->assertSee(route('invoices.force-destroy', $invoice), false);

        [$member, $memberOrg] = $this->createUserWithOrganization(Role::Member);
        app(OrganizationContext::class)->forceSet($memberOrg);
        $client = Client::factory()->create(['organization_id' => $memberOrg->id]);
        Invoice::factory()->create(['organization_id' => $memberOrg->id, 'client_id' => $client->id, 'number' => '2026099']);

        $this->actingAs($member)->get(route('invoices.index'))
            ->assertOk()
            ->assertDontSee('force-destroy', false);
    }

    public function test_show_page_offers_type_to_confirm_for_owner(): void
    {
        [$user, , $invoice] = $this->issuedInvoice();

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Smazat úplně')
            ->assertSee('2026013');
    }
}
