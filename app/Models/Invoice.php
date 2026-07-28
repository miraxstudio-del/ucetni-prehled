<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToOrganization, EncryptsAttributes, HasFactory, SoftDeletes;

    /**
     * Text na dokladu může obsahovat citlivé údaje. Číslo, VS, data a částky
     * zůstávají čitelné — jsou potřeba pro řazení, filtry, součty a párování
     * plateb a samy o sobě neprozradí, o koho ani o co jde.
     *
     * Snapshot nese jméno a adresu odběratele — citlivé stejně jako v tabulce
     * clients, proto šifrovaný.
     */
    protected $encrypted = ['note', 'snapshot'];

    protected $encryptedJson = ['snapshot'];

    protected $fillable = [
        'organization_id',
        'client_id',
        'direction',
        'type',
        'status',
        'number_series_id',
        'number',
        'variable_symbol',
        'issue_date',
        'duzp',
        'due_date',
        'payment_method',
        'bank_account_id',
        'currency',
        'subtotal',
        'vat_total',
        'total',
        'note',
        'corrected_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InvoiceDirection::class,
            'type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'payment_method' => PaymentMethod::class,
            'issue_date' => 'date',
            'duzp' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function numberSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class);
    }

    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function paymentMatches(): HasMany
    {
        return $this->hasMany(PaymentMatch::class);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true)
            && $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }

    /**
     * Zmrazí údaje dodavatele, odběratele a účtu k okamžiku vystavení.
     *
     * Vystavená faktura je dokument — pozdější přejmenování klienta nebo změna
     * adresy organizace už nesmí měnit její PDF/ISDOC. Volá se při vystavení
     * (InvoiceService::issue) a zpětně přes ucetni-prehled:snapshot-invoices.
     *
     * Jen naplní atribut; uložení řídí volající (bývá součástí větší transakce).
     */
    public function takeSnapshot(): void
    {
        $this->loadMissing(['organization', 'client', 'bankAccount']);

        $organization = $this->organization;
        $client = $this->client;
        $account = $this->bankAccount;

        $this->snapshot = [
            'taken_at' => now()->toIso8601String(),
            'organization' => [
                'name' => $organization->name,
                'ico' => $organization->ico,
                'dic' => $organization->dic,
                'vat_payer' => (bool) $organization->vat_payer,
                'street' => $organization->street,
                'city' => $organization->city,
                'zip' => $organization->zip,
                'country' => $organization->country,
                'email' => $organization->email,
                'phone' => $organization->phone,
                'registration_note' => $organization->registration_note,
                'invoice_footer' => $organization->invoice_footer,
            ],
            'client' => $client === null ? null : [
                'name' => $client->name,
                'ico' => $client->ico,
                'dic' => $client->dic,
                'street' => $client->street,
                'city' => $client->city,
                'zip' => $client->zip,
                'country' => $client->country,
            ],
            'bank_account' => $account === null ? null : [
                'name' => $account->name,
                'account_prefix' => $account->account_prefix,
                'account_number' => $account->account_number,
                'bank_code' => $account->bank_code,
                'iban' => $account->iban,
                'currency' => $account->currency,
            ],
        ];
    }

    /**
     * Údaje pro tisk dokladu: zmrazená kopie z okamžiku vystavení, u konceptů
     * (bez snapshotu) živá data. Vrací modely jen v paměti — chovají se v
     * šablonách stejně jako živé relace, ale nikdy se neukládají.
     *
     * Logo a razítko zůstávají živé záměrně (jsou to soubory, snapshot nese
     * jen texty) — stejně jako zvolený vzhled šablony.
     */
    public function displayOrganization(): ?Organization
    {
        $data = $this->snapshot['organization'] ?? null;

        if ($data === null) {
            return $this->organization;
        }

        $frozen = (new Organization)->forceFill($data);
        $frozen->logo_path = $this->organization?->logo_path;
        $frozen->stamp_path = $this->organization?->stamp_path;

        return $frozen;
    }

    public function displayClient(): ?Client
    {
        if ($this->snapshot === null) {
            return $this->client;
        }

        $data = $this->snapshot['client'] ?? null;

        return $data === null ? null : (new Client)->forceFill($data);
    }

    public function displayBankAccount(): ?BankAccount
    {
        if ($this->snapshot === null) {
            return $this->bankAccount;
        }

        $data = $this->snapshot['bank_account'] ?? null;

        return $data === null ? null : (new BankAccount)->forceFill($data);
    }

    /**
     * Přepne relace na zmrazená data, aby všechno navazující (Blade šablona,
     * QR platba, ISDOC) četlo snapshot jednotně. Nevolat před ukládáním —
     * podvržené relace se sice nepersistují, ale je to matoucí.
     */
    public function freezeForDocument(): void
    {
        if ($this->snapshot === null) {
            return;
        }

        $this->setRelation('organization', $this->displayOrganization());
        $this->setRelation('client', $this->displayClient());
        $this->setRelation('bankAccount', $this->displayBankAccount());
    }

    /** Rekapitulace DPH po sazbách: [sazba => ['base' => …, 'vat' => …]]. */
    public function vatBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->items as $item) {
            $rate = (string) $item->vat_rate;
            $breakdown[$rate]['base'] = bcadd($breakdown[$rate]['base'] ?? '0', (string) $item->line_subtotal, 2);
            $breakdown[$rate]['vat'] = bcadd($breakdown[$rate]['vat'] ?? '0', (string) $item->line_vat, 2);
        }

        krsort($breakdown, SORT_NUMERIC);

        return $breakdown;
    }

    /**
     * Číslo a VS jsou čitelné → hledá SQL. Jméno klienta je zašifrované →
     * odpovídající klienty najdeme dešifrováním v PHP (je jich málo) a na
     * faktury pak filtrujeme přes client_id, takže stránkování zůstává v SQL.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $clientIds = Client::query()
            ->get(['id', 'organization_id', 'name', 'ico', 'email', 'city'])
            ->filter(fn (Client $client) => $client->matchesSearch($term))
            ->pluck('id');

        return $query->where(function (Builder $q) use ($term, $clientIds) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('variable_symbol', 'like', "%{$term}%");

            if ($clientIds->isNotEmpty()) {
                $q->orWhereIn('client_id', $clientIds);
            }
        });
    }
}
