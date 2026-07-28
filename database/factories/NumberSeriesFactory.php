<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\NumberSeries;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSeries>
 */
class NumberSeriesFactory extends Factory
{
    protected $model = NumberSeries::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'document_type' => DocumentType::Invoice,
            'name' => 'Výchozí řada',
            'format' => '{YYYY}-{NNNN}',
            'year' => (int) now()->format('Y'),
            'next_number' => 1,
            'is_default' => true,
        ];
    }
}
