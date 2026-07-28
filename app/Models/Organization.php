<?php

namespace App\Models;

use App\Enums\InvoiceTemplate;
use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use EncryptsAttributes, HasFactory, SoftDeletes;

    /** Šifrováno vlastním datovým klíčem organizace (sloupec data_key). */
    protected $encrypted = [
        'name', 'ico', 'dic', 'street', 'city', 'zip',
        'email', 'phone', 'website', 'registration_note', 'invoice_footer',
    ];

    /** data_key je už zašifrovaný hlavním klíčem — ven se nikdy nedostane. */
    protected $hidden = ['data_key'];

    /** Zrcadlí výchozí hodnotu z migrace pro instance, které ještě nejsou z DB (náhled). */
    protected $attributes = [
        'invoice_template' => 'klasik',
    ];

    protected $fillable = [
        'name',
        'ico',
        'dic',
        'vat_payer',
        'street',
        'city',
        'zip',
        'country',
        'email',
        'phone',
        'website',
        'registration_note',
        'logo_path',
        'stamp_path',
        'default_due_days',
        'invoice_footer',
        'invoice_template',
    ];

    protected function casts(): array
    {
        return [
            'vat_payer' => 'boolean',
            'default_due_days' => 'integer',
            'invoice_template' => InvoiceTemplate::class,
        ];
    }

    /** Organizace šifruje sama sebe vlastním datovým klíčem. */
    protected function resolveOrganizationForEncryption(): ?Organization
    {
        return $this;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }
}
