<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 500, 25000);

        return [
            'invoice_id' => Invoice::factory(),
            'position' => 0,
            'description' => fake()->randomElement([
                'Vývoj webové aplikace',
                'Grafický návrh',
                'Konzultace',
                'Správa serveru',
                'Údržba webu',
            ]),
            'quantity' => 1,
            'unit' => fake()->randomElement(['ks', 'hod', null]),
            'unit_price' => $price,
            'vat_rate' => 0,
            'line_subtotal' => $price,
            'line_vat' => 0,
            'line_total' => $price,
        ];
    }
}
