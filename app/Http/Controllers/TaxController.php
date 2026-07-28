<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Tax\TaxCalculator;
use App\Services\Tax\TaxYearReport;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaxController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TaxYearReport $report,
        private readonly TaxCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $years = $this->calculator->availableYears();
        rsort($years);

        $year = (int) $request->integer('rok', config('tax.default_year'));

        if (! in_array($year, $years, true)) {
            $year = $years[0];
        }

        return view('tax.index', $this->report->build($this->context->current(), $year) + [
            'years' => $years,
        ]);
    }

    public function update(Request $request, int $year): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        abort_unless(in_array($year, $this->calculator->availableYears(), true), 404);

        $data = $request->validate([
            'secondary_activity' => ['boolean'],
            'secondary_activity_until' => ['nullable', 'date', function (string $attribute, mixed $value, \Closure $fail) use ($year) {
                if ((int) date('Y', strtotime((string) $value)) !== $year) {
                    $fail("Datum přechodu musí spadat do roku {$year}.");
                }
            }],
            'state_health_payer' => ['boolean'],
            'expense_mode' => ['required', Rule::in(['pausal', 'actual'])],
            'pausal_percent' => ['required_if:expense_mode,pausal', 'nullable', Rule::in(array_keys(config("tax.years.{$year}.pausal_caps")))],
            'actual_expenses' => ['required_if:expense_mode,actual', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
            'claim_taxpayer_credit' => ['boolean'],
            'claim_children' => ['boolean'],
            'claim_spouse_credit' => ['boolean'],
            'social_advances_paid' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'health_advances_paid' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'income_override' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'children' => 'počet dětí',
            'pausal_percent' => 'výdajový paušál',
            'actual_expenses' => 'skutečné výdaje',
            'income_override' => 'příjmy dle přiznání',
            'secondary_activity_until' => 'datum přechodu na hlavní činnost',
        ]);

        $profile = $this->report->profile($this->context->current(), $year);

        $profile->fill([
            'secondary_activity' => $request->boolean('secondary_activity'),
            'secondary_activity_until' => $data['secondary_activity_until'] ?? null,
            'state_health_payer' => $request->boolean('state_health_payer'),
            'pausal_percent' => $data['expense_mode'] === 'pausal' ? (int) $data['pausal_percent'] : null,
            'actual_expenses' => $data['expense_mode'] === 'actual' ? $data['actual_expenses'] : 0,
            'children' => $data['children'],
            'claim_taxpayer_credit' => $request->boolean('claim_taxpayer_credit'),
            'claim_children' => $request->boolean('claim_children'),
            'claim_spouse_credit' => $request->boolean('claim_spouse_credit'),
            'social_advances_paid' => $data['social_advances_paid'] ?? 0,
            'health_advances_paid' => $data['health_advances_paid'] ?? 0,
            'income_override' => $data['income_override'] ?? null,
            'note' => $data['note'] ?? null,
        ])->save();

        $this->audit->log('tax.settings_updated', meta: ['year' => $year]);

        return redirect()
            ->route('tax.index', ['rok' => $year])
            ->with('status', "Nastavení pro rok {$year} uloženo.");
    }
}
