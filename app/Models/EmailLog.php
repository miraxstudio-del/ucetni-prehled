<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use BelongsToOrganization, EncryptsAttributes;

    /** Komu a s jakým předmětem jsme psali — prozradí klienty. */
    protected $encrypted = ['to', 'subject'];

    protected $table = 'email_log';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'to',
        'subject',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
