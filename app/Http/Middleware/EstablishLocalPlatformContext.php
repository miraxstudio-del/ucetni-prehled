<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Prepares the single local profile and company for every request. */
class EstablishLocalPlatformContext
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()->oldest('id')->first();

        if ($user === null) {
            $user = User::create([
                'name' => 'Lokalni uzivatel',
                'email' => 'local@ucetni-prehled.test',
                'email_verified_at' => now(),
                'password' => Str::password(40),
            ]);
        }

        $membership = Membership::query()
            ->with('organization')
            ->where('user_id', $user->id)
            ->oldest('id')
            ->first();

        if ($membership === null) {
            $organization = Organization::query()->oldest('id')->first()
                ?? Organization::create([
                    'name' => 'Moje firma',
                    'country' => 'CZ',
                    'default_due_days' => 14,
                ]);

            $membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'role' => Role::Owner,
            ]);
            $membership->setRelation('organization', $organization);
        }

        Auth::guard('web')->login($user);
        $this->context->forceSet($membership->organization, $membership);

        return $next($request);
    }
}
