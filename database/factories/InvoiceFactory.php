<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $issueDate = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'organization_id' => Organization::factory(),
            'client_id' => Client::factory(),
            'direction' => InvoiceDirection::Issued,
            'type' => DocumentType::Invoice,
            'status' => InvoiceStatus::Draft,
            'issue_date' => $issueDate,
            'duzp' => $issueDate,
            'due_date' => (clone $issueDate)->modify('+14 days'),
            'payment_method' => PaymentMethod::BankTransfer,
            'currency' => 'CZK',
            'subtotal' => 0,
            'vat_total' => 0,
            'total' => 0,
        ];
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
