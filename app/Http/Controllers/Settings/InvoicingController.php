<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DocumentType;
use App\Enums\InvoiceTemplate;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\NumberSeries;
use App\Services\Audit\AuditLogger;
use App\Services\Invoicing\CzechIban;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoicingController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        // zápis do nastavení jen pro role s právem zápisu (owner/member)
        $this->authorize('create', Client::class);

        return view('settings.invoicing', [
            'series' => NumberSeries::orderBy('document_type')->orderByDesc('year')->get(),
            'accounts' => BankAccount::orderByDesc('is_default')->get(),
            'organization' => $this->context->current(),
            'isOwner' => $this->context->role() === Role::Owner,
            'templates' => InvoiceTemplate::cases(),
        ]);
    }

    public function storeSeries(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $validated = $request->validate([
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'name' => ['required', 'string', 'max:100'],
            'format' => ['required', 'string', 'max:40', 'regex:/\{N+\}/'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'next_number' => ['required', 'integer', 'min:1'],
        ], [
            'format.regex' => 'Formát musí obsahovat pořadí, např. {NNNN}.',
        ], [
            'name' => 'název', 'format' => 'formát', 'year' => 'rok',
            'next_number' => 'další číslo',
        ]);

        $series = NumberSeries::create($validated);

        $this->audit->log('number_series.created', entity: $series);

        return back()->with('status', 'Číselná řada byla vytvořena.');
    }

    public function destroySeries(NumberSeries $series): RedirectResponse
    {
        $this->authorize('create', Client::class);

        if ($series->invoices()->exists()) {
            throw ValidationException::withMessages([
                'series' => 'Řadu nelze smazat — už z ní byly vystaveny doklady.',
            ]);
        }

        $series->delete();

        $this->audit->log('number_series.deleted', meta: ['name' => $series->name]);

        return back()->with('status', 'Číselná řada byla smazána.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'account' => ['required', 'string', 'max:25'],
        ], [], ['name' => 'název účtu', 'account' => 'číslo účtu']);

        $parsed = CzechIban::parseNational($validated['account']);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                'account' => 'Číslo účtu zadejte ve formátu 123-1234567890/0100.',
            ]);
        }

        $account = BankAccount::create([
            'name' => $validated['name'],
            'account_prefix' => $parsed['prefix'],
            'account_number' => $parsed['number'],
            'bank_code' => $parsed['bank_code'],
            'iban' => CzechIban::fromNational($parsed['prefix'], $parsed['number'], $parsed['bank_code']),
            'currency' => 'CZK',
            'is_default' => BankAccount::count() === 0,
        ]);

        $this->audit->log('bank_account.created', entity: $account);

        return back()->with('status', 'Bankovní účet byl přidán.');
    }

    public function setDefaultAccount(BankAccount $account): RedirectResponse
    {
        $this->authorize('create', Client::class);

        BankAccount::query()->update(['is_default' => false]);
        $account->update(['is_default' => true]);

        return back()->with('status', 'Výchozí účet byl změněn.');
    }

    public function destroyAccount(BankAccount $account): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $account->delete();

        $this->audit->log('bank_account.deleted', meta: ['number' => $account->displayNumber()]);

        return back()->with('status', 'Bankovní účet byl odebrán.');
    }
}
