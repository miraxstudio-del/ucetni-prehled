<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NumberSeries extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'number_series';

    protected $fillable = [
        'organization_id',
        'document_type',
        'name',
        'format',
        'year',
        'next_number',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'year' => 'integer',
            'next_number' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Náhled dalšího čísla bez alokace. */
    public function preview(): string
    {
        return $this->formatNumber($this->next_number);
    }

    public function formatNumber(int $number): string
    {
        $result = str_replace(
            ['{YYYY}', '{YY}'],
            [(string) $this->year, substr((string) $this->year, -2)],
            $this->format,
        );

        return (string) preg_replace_callback(
            '/\{(N+)\}/',
            fn ($m) => str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT),
            $result,
        );
    }
}
