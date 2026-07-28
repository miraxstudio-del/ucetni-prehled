<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Tenant izolace — bezpečnostně kritické.
 *
 * Každý model s tímto traitem je automaticky filtrován na aktivní organizaci
 * (globální scope) a při vytvoření dostane organization_id z kontextu.
 * Vytvoření záznamu bez kontextu vyhodí výjimku — data nikdy nesmí
 * vzniknout mimo organizaci.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $context = app(OrganizationContext::class);

            // fail-safe: pokud kontext ještě není nastaven (např. dotaz před
            // middlewarem), dovodí se z přihlášeného uživatele — dotaz na
            // tenant data nikdy nesmí běžet bez izolace
            if (! $context->check() && auth()->hasUser()) {
                $context->resolve(auth()->user());
            }

            if ($context->check()) {
                $builder->where(
                    $builder->getModel()->getTable().'.organization_id',
                    $context->id()
                );
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('organization_id') !== null) {
                return;
            }

            $context = app(OrganizationContext::class);

            if (! $context->check()) {
                throw new RuntimeException(sprintf(
                    'Nelze vytvořit %s bez aktivní organizace.',
                    static::class
                ));
            }

            $model->setAttribute('organization_id', $context->id());
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Vědomé obejití scope — používat výhradně v CLI/cron s vlastním filtrem. */
    public static function acrossAllOrganizations(): Builder
    {
        return static::query()->withoutGlobalScope('organization');
    }
}
