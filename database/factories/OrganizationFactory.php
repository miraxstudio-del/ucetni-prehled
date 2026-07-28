<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'ico' => (string) fake()->numberBetween(10000000, 99999999),
            'dic' => null,
            'vat_payer' => false,
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'zip' => str_replace(' ', '', fake()->postcode()),
            'country' => 'CZ',
            'email' => fake()->companyEmail(),
            'default_due_days' => 14,
        ];
    }

    public function vatPayer(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_payer' => true,
            'dic' => 'CZ'.$attributes['ico'],
        ]);
    }
}
