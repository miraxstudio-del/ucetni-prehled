<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Drží aktivní organizaci přihlášeného uživatele (session) a vynucuje,
 * že uživatel je jejím členem. Jediný zdroj pravdy pro tenant scope.
 */
class OrganizationContext
{
    private const SESSION_KEY = 'current_organization_id';

    private ?Organization $organization = null;

    private ?Membership $membership = null;

    public function set(User $user, Organization $organization): void
    {
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->first();

        if ($membership === null) {
            abort(403, 'Nejste členem této organizace.');
        }

        Session::put(self::SESSION_KEY, $organization->id);
        $this->organization = $organization;
        $this->membership = $membership;
    }

    /** Obnoví kontext ze session; vrací null, pokud uživatel nemá platnou organizaci. */
    public function resolve(User $user): ?Organization
    {
        // cache platí jen pro stejného uživatele — jinak hrozí únik kontextu
        // mezi requesty v dlouho běžícím procesu (testy, Octane)
        if ($this->organization !== null && $this->membership?->user_id === $user->id) {
            return $this->organization;
        }

        $this->organization = null;
        $this->membership = null;

        $id = Session::get(self::SESSION_KEY);

        $membership = Membership::query()
            ->with('organization')
            ->where('user_id', $user->id)
            ->when($id, fn ($q) => $q->where('organization_id', $id))
            ->first();

        // session ukazuje na organizaci, kde už uživatel není členem → zkus první dostupnou
        if ($membership === null && $id !== null) {
            Session::forget(self::SESSION_KEY);

            $membership = Membership::query()
                ->with('organization')
                ->where('user_id', $user->id)
                ->first();
        }

        if ($membership === null || $membership->organization === null) {
            return null;
        }

        Session::put(self::SESSION_KEY, $membership->organization_id);
        $this->organization = $membership->organization;
        $this->membership = $membership;

        return $this->organization;
    }

    public function current(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function role(): ?Role
    {
        return $this->membership?->role;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    /** Pro testy a CLI (cron, fronty) — nastaví kontext bez session. */
    public function forceSet(Organization $organization, ?Membership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
        $this->organization = null;
        $this->membership = null;
    }
}
