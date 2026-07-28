<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Organization;
use App\Services\Invoicing\CzechIban;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        $number = (string) fake()->numberBetween(1000000000, 9999999999);
        $bankCode = fake()->randomElement(['0100', '0300', '0800', '2010', '3030']);

        return [
            'organization_id' => Organization::factory(),
            'name' => 'Běžný účet',
            'account_prefix' => null,
            'account_number' => $number,
            'bank_code' => $bankCode,
            'iban' => CzechIban::fromNational(null, $number, $bankCode),
            'currency' => 'CZK',
            'is_default' => true,
        ];
    }
}
