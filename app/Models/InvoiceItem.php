<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use EncryptsAttributes, HasFactory;

    /** Popis plnění je citlivý — šifruje se klíčem organizace faktury. */
    protected $encrypted = ['description'];

    protected $fillable = [
        'invoice_id',
        'position',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'vat_rate',
        'line_subtotal',
        'line_vat',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_vat' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Položka nemá organization_id — bere ji z faktury, ke které patří. */
    protected function resolveOrganizationForEncryption(): ?Organization
    {
        $invoiceId = $this->getAttributeFromArray('invoice_id');

        if ($invoiceId === null) {
            return null;
        }

        $organizationId = Invoice::withoutGlobalScopes()
            ->whereKey($invoiceId)
            ->value('organization_id');

        return $organizationId === null
            ? null
            : Organization::withoutGlobalScopes()->find($organizationId);
    }
}
