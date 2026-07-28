<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class InvoicePolicy
{
    use ChecksOrganizationRole;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite();
    }

    /** Editovat lze jen koncept. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->canWrite() && $invoice->status->isEditable();
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->canWrite() && $invoice->status === InvoiceStatus::Draft;
    }

    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $this->canWrite()
            && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->canWrite() && $invoice->status !== InvoiceStatus::Cancelled;
    }

    public function duplicate(User $user, Invoice $invoice): bool
    {
        return $this->canWrite();
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $this->canWrite()
            && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true);
    }

    /** Smazat (do koše) lze jen koncept — vystavené doklady se stornují. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->canWrite() && $invoice->status === InvoiceStatus::Draft;
    }

    /**
     * Trvalé smazání i vystavené/zaplacené faktury — obchází storno a
     * vytvoří díru v číselné řadě, proto jen vlastník organizace.
     */
    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return $this->role() === Role::Owner;
    }
}
