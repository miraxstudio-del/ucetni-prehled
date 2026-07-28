<?php

namespace App\Console\Commands;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Mail\OverdueReminderMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Automatická připomínka klientům po splatnosti (spouští scheduler denně).
 * Každá faktura dostane připomínku jen jednou (reminder_sent_at).
 */
class SendOverdueReminders extends Command
{
    protected $signature = 'invoices:send-overdue-reminders';

    protected $description = 'Odešle klientům připomínky k fakturám po splatnosti';

    public function handle(OrganizationContext $context): int
    {
        // načíst vše před nastavením kontextu — poté by tenant scope filtroval
        $invoices = Invoice::acrossAllOrganizations()
            ->with(['client', 'organization'])
            ->where('direction', InvoiceDirection::Issued)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])
            ->whereDate('due_date', '<', today())
            ->whereNull('reminder_sent_at')
            ->get()
            ->filter(fn (Invoice $invoice) => filled($invoice->client?->email));

        $sent = 0;

        foreach ($invoices as $invoice) {
            $context->forceSet($invoice->organization);

            try {
                Mail::to($invoice->client->email)->send(new OverdueReminderMail($invoice));

                EmailLog::create([
                    'invoice_id' => $invoice->id,
                    'to' => $invoice->client->email,
                    'subject' => 'Připomínka: faktura '.$invoice->number.' po splatnosti',
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $invoice->forceFill(['reminder_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Odeslání připomínky selhalo: '.$e->getMessage(), [
                    'invoice_id' => $invoice->id,
                ]);
            }
        }

        $context->clear();

        $this->info("Odesláno připomínek: {$sent}");

        return self::SUCCESS;
    }
}
