<?php

namespace App\Policies\Concerns;

use App\Enums\Role;
use App\Support\OrganizationContext;

trait ChecksOrganizationRole
{
    /** Role uživatele v aktivní organizaci (nastavuje middleware org). */
    protected function role(): ?Role
    {
        return app(OrganizationContext::class)->role();
    }

    protected function canWrite(): bool
    {
        return (bool) $this->role()?->canWrite();
    }
}
