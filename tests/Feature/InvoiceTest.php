<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Mail\InvoiceMail;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'direction' => 'issued',
            'type' => 'invoice',
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'duzp' => null,
            'due_date' => now()->addDays(14)->toDateString(),
            'payment_method' => 'bank_transfer',
            'bank_account_id' => null,
            'variable_symbol' => null,
            'number' => null,
            'note' => null,
            'items' => [
                ['description' => 'Vývoj aplikace', 'quantity' => '2', 'unit' => 'hod', 'unit_price' => '850', 'vat_rate' => '0'],
            ],
        ], $overrides);
    }

    public function test_draft_invoice_is_created_with_calculated_totals(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'draft']));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame('1700.00', (string) $invoice->total);
        $this->assertCount(1, $invoice->items);
    }

    public function test_issuing_assigns_sequential_numbers_and_variable_symbol(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));
        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));

        $numbers = Invoice::acrossAllOrganizations()->orderBy('id')->pluck('number');
        $year = now()->year;

        $this->assertSame(["{$year}-0001", "{$year}-0002"], $numbers->all());

        $first = Invoice::acrossAllOrganizations()->orderBy('id')->first();
        $this->assertSame($year.'0001', $first->variable_symbol);
        $this->assertSame(InvoiceStatus::Issued, $first->status);
        $this->assertNotNull($first->duzp);
    }

    public function test_vat_is_zeroed_for_non_vat_payer(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, [
            'items' => [
                ['description' => 'Test', 'quantity' => '1', 'unit' => null, 'unit_price' => '1000', 'vat_rate' => '21'],
            ],
        ]));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->assertSame('0.00', (string) $invoice->vat_total);
        $this->assertSame('1000.00', (string) $invoice->total);
    }

    public function test_vat_is_calculated_for_vat_payer(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->vatPayer()->create();
        $organization->memberships()->create(['user_id' => $user->id, 'role' => Role::Owner]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, [
            'items' => [
                ['description' => 'Test', 'quantity' => '1', 'unit' => null, 'unit_price' => '1000', 'vat_rate' => '21'],
            ],
        ]));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->assertSame('210.00', (string) $invoice->vat_total);
        $this->assertSame('1210.00', (string) $invoice->total);
    }

    public function test_invoice_can_be_duplicated_as_draft(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));
        $original = Invoice::acrossAllOrganizations()->first();

        $this->actingAs($user)->post(route('invoices.duplicate', $original));

        $copy = Invoice::acrossAllOrganizations()->where('id', '!=', $original->id)->first();

        $this->assertSame(InvoiceStatus::Draft, $copy->status);
        $this->assertNull($copy->number);
        $this->assertSame((string) $original->total, (string) $copy->total);
        $this->assertCount($original->items->count(), $copy->items);
    }

    public function test_issued_invoice_cannot_be_edited_or_deleted_only_cancelled(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));
        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->actingAs($user)->delete(route('invoices.destroy', $invoice))->assertForbidden();

        $this->actingAs($user)->post(route('invoices.cancel', $invoice));

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->cancelled_at);
    }

    public function test_invoice_can_be_marked_paid(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));
        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->actingAs($user)->post(route('invoices.paid', $invoice), ['paid_at' => now()->toDateString()]);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_cannot_use_foreign_client_on_invoice(): void
    {
        [$user] = $this->createUserWithOrganization();
        [, $foreignOrg] = $this->createUserWithOrganization();
        $foreignClient = Client::factory()->create(['organization_id' => $foreignOrg->id]);

        $this->actingAs($user)
            ->post('/faktury', $this->payload($foreignClient))
            ->assertNotFound();

        $this->assertSame(0, Invoice::acrossAllOrganizations()->count());
    }

    public function test_pdf_is_generated_with_qr_payment(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $account = BankAccount::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, [
            'bank_account_id' => $account->id,
            'action' => 'issue',
        ]));

        $invoice = Invoice::acrossAllOrganizations()->first();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_can_be_sent_by_email_and_logged(): void
    {
        Mail::fake();

        [$user, $organization] = $this->createUserWithOrganization();
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client, ['action' => 'issue']));
        $invoice = Invoice::acrossAllOrganizations()->first();

        $this->actingAs($user)->post(route('invoices.send', $invoice), [
            'to' => 'klient@example.com',
        ]);

        Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->hasTo('klient@example.com'));

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        // adresát je v email logu zašifrovaný → ověříme přes model
        $log = EmailLog::acrossAllOrganizations()
            ->where('invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame('klient@example.com', $log->to);
        $this->assertSame('sent', $log->status);
    }

    public function test_accountant_cannot_create_invoices(): void
    {
        [$user, $organization] = $this->createUserWithOrganization(Role::Accountant);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->post('/faktury', $this->payload($client))->assertForbidden();
    }
}
