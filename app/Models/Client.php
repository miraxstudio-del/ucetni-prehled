<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use App\Services\Security\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToOrganization, EncryptsAttributes, HasFactory, SoftDeletes;

    protected $encrypted = [
        'name', 'ico', 'dic', 'street', 'city', 'zip', 'email', 'phone', 'note',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'ico',
        'dic',
        'street',
        'city',
        'zip',
        'country',
        'email',
        'phone',
        'note',
        'due_days',
    ];

    protected function casts(): array
    {
        return [
            'due_days' => 'integer',
        ];
    }

    /** Slepé indexy držíme v synchronizaci s šifrovanými hodnotami. */
    protected static function booted(): void
    {
        static::saving(function (Client $client) {
            $index = app(BlindIndex::class);

            foreach (['name', 'ico', 'email'] as $field) {
                if ($client->isDirty($field)) {
                    $client->attributes[$field.'_index'] = $index->make($client->{$field}, 'clients.'.$field);
                }
            }
        });
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Přesná shoda přes slepý index (hodnota je v DB zašifrovaná). */
    public function scopeWhereIco(Builder $query, string $ico): Builder
    {
        return $query->where('ico_index', app(BlindIndex::class)->make($ico, 'clients.ico'));
    }

    public function scopeWhereName(Builder $query, string $name): Builder
    {
        return $query->where('name_index', app(BlindIndex::class)->make($name, 'clients.name'));
    }

    /**
     * Fulltext nelze dělat v SQL (sloupce jsou zašifrované) — porovnává se
     * po dešifrování v PHP. Klientů jsou řádově desítky, takže to nevadí.
     */
    public function matchesSearch(?string $term): bool
    {
        if (blank($term)) {
            return true;
        }

        $needle = mb_strtolower(trim($term));

        foreach ([$this->name, $this->ico, $this->email, $this->city] as $value) {
            if ($value !== null && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Celkový obrat = součet vydaných nestornovaných faktur klienta. */
    public function totalRevenue(): string
    {
        return (string) $this->invoices()
            ->where('direction', 'issued')
            ->whereNull('cancelled_at')
            ->sum('total');
    }

    public function fullAddress(): string
    {
        return collect([$this->street, trim($this->zip.' '.$this->city)])
            ->filter()
            ->implode(', ');
    }
}
