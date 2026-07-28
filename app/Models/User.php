<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAttributes;
use App\Services\Security\BlindIndex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use EncryptsAttributes, HasFactory, Notifiable;

    /** Uživatel nepatří jedné organizaci → globální klíč odvozený z KEK. */
    protected $encryptWithGlobalKey = true;

    protected $encrypted = ['name', 'email'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'totp_recovery_codes',
        'recovery_phrase_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'totp_recovery_codes' => 'encrypted:array',
            'last_login_at' => 'datetime',
            'recovery_phrase_created_at' => 'datetime',
            'recovery_phrase_used_at' => 'datetime',
        ];
    }

    /** Slepý index e-mailu držíme automaticky v synchronizaci s hodnotou. */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('email')) {
                $user->attributes['email_index'] = app(BlindIndex::class)->make($user->email, 'users.email');
            }
        });
    }

    /** Vyhledání podle e-mailu — v DB je zašifrovaný, hledá se přes slepý index. */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where('email_index', app(BlindIndex::class)->make($email, 'users.email'));
    }

    /**
     * Token pro obnovu hesla se v tabulce páruje na tuto hodnotu — vracíme
     * slepý index, aby v password_reset_tokens nebyl čitelný e-mail.
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) app(BlindIndex::class)->make($this->email, 'users.email');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

}
