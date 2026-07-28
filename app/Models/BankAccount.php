<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use BelongsToOrganization, EncryptsAttributes, HasFactory, SoftDeletes;

    /** Číslo účtu a IBAN jsou citlivé; kód banky sám o sobě nic neprozradí. */
    protected $encrypted = ['name', 'account_prefix', 'account_number', 'iban'];

    protected $fillable = [
        'organization_id',
        'name',
        'account_prefix',
        'account_number',
        'bank_code',
        'iban',
        'currency',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** Číslo účtu v národním formátu, např. 123-1234567890/0100. */
    public function displayNumber(): string
    {
        $prefix = $this->account_prefix ? $this->account_prefix.'-' : '';

        return $prefix.$this->account_number.'/'.$this->bank_code;
    }
}
