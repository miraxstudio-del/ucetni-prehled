<?php

namespace App\Services\Invoicing;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\InvoicePdf;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly NumberSeriesService $numberSeries,
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Uloží koncept (nový či existující) včetně přepočtu položek. */
    public function saveDraft(array $data, array $items, ?Invoice $invoice = null): Invoice
    {
        $organization = $this->context->current();

        // U přijatých faktur evidujeme DPH dodavatele bez ohledu na náš režim
        $direction = InvoiceDirection::from($data['direction']);
        $withVat = $direction === InvoiceDirection::Received || $organization->vat_payer;

        $calculated = $this->calculator->calculate($items, $withVat);

        return DB::transaction(function () use ($data, $calculated, $invoice) {
            $attributes = [
                ...$data,
                'subtotal' => $calculated['subtotal'],
                'vat_total' => $calculated['vat_total'],
                'total' => $calculated['total'],
            ];

            if ($invoice === null) {
                $invoice = Invoice::create($attributes);
                $this->audit->log('invoice.created', entity: $invoice);
            } else {
                $invoice->update($attributes);
                $invoice->items()->delete();
                $this->audit->log('invoice.updated', entity: $invoice);
            }

            $invoice->items()->createMany($calculated['items']);

            return $invoice->refresh();
        });
    }

    /** Vystaví koncept: přidělí číslo z řady, VS a nastaví stav. */
    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->direction === InvoiceDirection::Issued && $invoice->number === null) {
                $series = $invoice->numberSeries
                    ?? $this->numberSeries->defaultFor($invoice->type, $invoice->issue_date->year);

                [$number, $variableSymbol] = $this->numberSeries->allocate($series);

                $invoice->number_series_id = $series->id;
                $invoice->number = $number;
                $invoice->variable_symbol = $invoice->variable_symbol ?: $variableSymbol;
            }

            if ($invoice->duzp === null && $invoice->type !== DocumentType::Proforma) {
                $invoice->duzp = $invoice->issue_date;
            }

            $invoice->status = InvoiceStatus::Issued;

            // Vystavením se doklad stává neměnným — údaje dodavatele,
            // odběratele a účtu se zmrazí, aby pozdější úpravy klienta nebo
            // organizace nezměnily zpětně už vystavené PDF.
            $invoice->takeSnapshot();

            $invoice->save();

            $this->audit->log('invoice.issued', entity: $invoice, meta: ['number' => $invoice->number]);

            return $invoice;
        });
    }

    public function duplicate(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $copy = $invoice->replicate([
                'number', 'number_series_id', 'variable_symbol', 'status',
                'sent_at', 'paid_at', 'cancelled_at', 'reminder_sent_at',
                // kopie je nový koncept — zmrazená data originálu se nedědí
                'snapshot',
            ]);

            $copy->status = InvoiceStatus::Draft;
            $copy->issue_date = now()->toDateString();
            $copy->duzp = null;
            $copy->due_date = now()->addDays(
                $invoice->client?->due_days ?? $this->context->current()->default_due_days
            )->toDateString();
            $copy->save();

            foreach ($invoice->items as $item) {
                $copy->items()->create($item->only([
                    'position', 'description', 'quantity', 'unit', 'unit_price',
                    'vat_rate', 'line_subtotal', 'line_vat', 'line_total',
                ]));
            }

            $this->audit->log('invoice.duplicated', entity: $copy, meta: ['source_id' => $invoice->id]);

            return $copy;
        });
    }

    public function markPaid(Invoice $invoice, ?string $paidAt = null): Invoice
    {
        $invoice->update([
            'status' => InvoiceStatus::Paid,
        ]);
        $invoice->forceFill(['paid_at' => $paidAt ?: now()->toDateString()])->save();

        $this->audit->log('invoice.paid', entity: $invoice);

        return $invoice;
    }

    /** Storno — vystavené doklady se nemažou (měkké mazání dle zadání). */
    public function cancel(Invoice $invoice): Invoice
    {
        $invoice->status = InvoiceStatus::Cancelled;
        $invoice->cancelled_at = now();
        $invoice->save();

        $this->audit->log('invoice.cancelled', entity: $invoice, meta: ['number' => $invoice->number]);

        return $invoice;
    }

    /**
     * Trvale smaže fakturu bez ohledu na stav — obchází soft delete i storno.
     * Audit log nese kompletní identifikaci (číslo, částku, klienta), protože
     * po smazání už tyhle údaje z databáze nejde dohledat jinak.
     */
    public function forceDelete(Invoice $invoice): void
    {
        $meta = [
            'number' => $invoice->number,
            'total' => (string) $invoice->total,
            'client' => $invoice->client?->name,
            'status' => $invoice->status->value,
        ];

        $invoice->forceDelete();

        $this->audit->log('invoice.force_deleted', entity: $invoice, meta: $meta);
    }

    /** Odešle fakturu e-mailem s PDF přílohou a zapíše do email logu. */
    public function send(Invoice $invoice, string $to, ?string $message, InvoicePdf $pdf): void
    {
        $subject = sprintf(
            '%s %s — %s',
            $invoice->type->label(),
            $invoice->number,
            $this->context->current()->name,
        );

        $log = EmailLog::create([
            'invoice_id' => $invoice->id,
            'to' => $to,
            'subject' => $subject,
            'status' => 'sent',
        ]);

        try {
            Mail::to($to)->send(new InvoiceMail($invoice, $subject, $message, $pdf));

            $log->update(['sent_at' => now()]);

            $invoice->status = InvoiceStatus::Sent;
            $invoice->sent_at = now();
            $invoice->save();

            $this->audit->log('invoice.sent', entity: $invoice, meta: ['to' => $to]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
