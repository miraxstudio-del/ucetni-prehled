<?php

namespace App\Policies;

use App\Models\BankTransaction;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class BankTransactionPolicy
{
    use ChecksOrganizationRole;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BankTransaction $transaction): bool
    {
        return true;
    }

    /** Sync, napojení, import a párování — jen role s právem zápisu. */
    public function manage(User $user): bool
    {
        return $this->canWrite();
    }

    /** Export smí i účetní (role = čtení + exporty). */
    public function export(User $user): bool
    {
        return true;
    }
}
