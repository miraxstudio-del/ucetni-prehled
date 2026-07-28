<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    use BelongsToOrganization, EncryptsAttributes, HasFactory;

    /**
     * Protistrana a zpráva prozrazují, s kým obchoduješ → šifrované.
     * raw obsahuje celou odpověď z banky (včetně jmen) → taky.
     * VS, částka a datum zůstávají čitelné kvůli párování plateb a součtům.
     */
    protected $encrypted = ['counterparty_account', 'counterparty_name', 'message', 'raw'];

    /** raw se ukládá jako zašifrovaný JSON (ne přes cast 'array'). */
    protected $encryptedJson = ['raw'];

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'external_id',
        'booked_on',
        'amount',
        'currency',
        'counterparty_account',
        'counterparty_name',
        'variable_symbol',
        'constant_symbol',
        'specific_symbol',
        'message',
        'import_source',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'decimal:2',
            // 'raw' zde záměrně není — řeší ho šifrování jako JSON
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PaymentMatch::class);
    }

    public function isCredit(): bool
    {
        return bccomp((string) $this->amount, '0', 2) > 0;
    }

    /**
     * Protistrana a zpráva jsou zašifrované → fulltext se vyhodnocuje
     * v PHP po dešifrování (viz matchesSearch v BankController).
     * V SQL zůstává jen variabilní symbol.
     */
    public function scopeSearchVariableSymbol(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('variable_symbol', 'like', "%{$term}%");
    }

    public function matchesSearch(?string $term): bool
    {
        if (blank($term)) {
            return true;
        }

        $needle = mb_strtolower(trim($term));

        foreach ([$this->counterparty_name, $this->counterparty_account, $this->variable_symbol, $this->message] as $value) {
            if ($value !== null && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
