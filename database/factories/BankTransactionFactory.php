<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    protected $model = BankTransaction::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'bank_account_id' => BankAccount::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(10000000000, 99999999999),
            'booked_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, -30000, 50000),
            'currency' => 'CZK',
            'counterparty_account' => fake()->numberBetween(1000000000, 9999999999).'/0800',
            'counterparty_name' => fake()->company(),
            'variable_symbol' => (string) fake()->numberBetween(20260001, 20260099),
            'message' => fake()->optional()->sentence(3),
            'import_source' => 'manual',
        ];
    }

    public function credit(string $amount, ?string $vs = null): static
    {
        return $this->state(fn () => [
            'amount' => $amount,
            'variable_symbol' => $vs,
        ]);
    }
}
