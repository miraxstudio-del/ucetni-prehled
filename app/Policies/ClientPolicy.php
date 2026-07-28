<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

/**
 * Tenant izolaci řeší globální scope (cizí záznamy = 404);
 * policy řeší role — účetní má pouze čtení.
 */
class ClientPolicy
{
    use ChecksOrganizationRole;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite();
    }

    public function update(User $user, Client $client): bool
    {
        return $this->canWrite();
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->canWrite();
    }
}
