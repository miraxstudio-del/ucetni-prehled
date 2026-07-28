<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use EncryptsAttributes;

    /**
     * Globální klíč — audit log vzniká i bez organizace (neúspěšné přihlášení).
     */
    protected $encryptWithGlobalKey = true;

    /** IP, prohlížeč a meta (obsahuje e-maily, čísla dokladů) jsou citlivé. */
    protected $encrypted = ['ip', 'user_agent', 'meta'];

    protected $encryptedJson = ['meta'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'ip',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            // 'meta' zde záměrně není — řeší ho šifrování jako JSON
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
