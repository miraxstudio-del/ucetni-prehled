<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company(),
            'ico' => (string) fake()->numberBetween(10000000, 99999999),
            'dic' => null,
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'zip' => str_replace(' ', '', fake()->postcode()),
            'country' => 'CZ',
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
        ];
    }
}
