<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audit log — kdo, kdy, co, z jaké IP. Zápis nikdy nesmí shodit
 * hlavní operaci; selhání se loguje do aplikačního logu.
 */
class AuditLogger
{
    public function log(
        string $action,
        ?Model $entity = null,
        array $meta = [],
        ?User $user = null,
        ?int $organizationId = null,
    ): void {
        try {
            $request = request();

            AuditLog::create([
                'organization_id' => $organizationId ?? app(OrganizationContext::class)->id(),
                'user_id' => $user?->id ?? Auth::id(),
                'action' => $action,
                'entity_type' => $entity ? $entity->getMorphClass() : null,
                'entity_id' => $entity?->getKey(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (Throwable $e) {
            Log::warning('Zápis do audit logu selhal: '.$e->getMessage(), [
                'action' => $action,
            ]);
        }
    }
}
