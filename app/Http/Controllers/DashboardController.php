<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context): View
    {
        $issued = Invoice::query()
            ->where('direction', InvoiceDirection::Issued)
            ->where('status', '!=', InvoiceStatus::Cancelled);

        $invoicedThisMonth = (clone $issued)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total');

        $invoicedLastMonth = (clone $issued)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->whereBetween('issue_date', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()])
            ->sum('total');

        $paid = (clone $issued)
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('issue_date', [now()->startOfYear(), now()])
            ->sum('total');

        // stejné období (1. 1. – dnešek) loňského roku, ať jde o férové srovnání
        $paidSamePeriodLastYear = (clone $issued)
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('issue_date', [now()->subYearNoOverflow()->startOfYear(), now()->subYearNoOverflow()])
            ->sum('total');

        $awaiting = (clone $issued)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])
            ->whereDate('due_date', '>=', today())
            ->sum('total');

        $overdue = (clone $issued)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])
            ->whereDate('due_date', '<', today())
            ->sum('total');

        $year = $request->integer('rok') ?: now()->year;

        return view('dashboard', [
            'organization' => $context->current(),
            'cards' => [
                [
                    'label' => 'Fakturováno tento měsíc', 'icon' => 'lucide-file-text',
                    'color' => 'primary', 'value' => $invoicedThisMonth,
                    'trend' => $this->trend((float) $invoicedThisMonth, (float) $invoicedLastMonth, 'vs. minulý měsíc'),
                ],
                [
                    'label' => 'Zaplaceno letos', 'icon' => 'lucide-wallet',
                    'color' => 'emerald', 'value' => $paid,
                    'trend' => $this->trend((float) $paid, (float) $paidSamePeriodLastYear, 'vs. stejné období loni'),
                ],
                [
                    'label' => 'Čeká na úhradu', 'icon' => 'lucide-clock',
                    'color' => 'amber', 'value' => $awaiting, 'trend' => null,
                ],
                [
                    'label' => 'Po splatnosti', 'icon' => 'lucide-triangle-alert',
                    'color' => 'red', 'value' => $overdue, 'trend' => null,
                ],
            ],
            'chartYear' => $year,
            'monthlyIncome' => $this->monthlyIncome($year),
            'recentInvoices' => Invoice::query()
                ->with('client')
                ->where('direction', InvoiceDirection::Issued)
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->limit(6)
                ->get(),
            'recentTransactions' => BankTransaction::query()
                ->orderByDesc('booked_on')
                ->orderByDesc('id')
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * Procentuální změna oproti srovnávacímu období. Když je základna 0,
     * procento nemá smysl (dělení nulou) — vrátí se null a karta ukáže
     * neutrální stav místo vymyšleného čísla.
     */
    private function trend(float $current, float $previous, string $label): ?array
    {
        if ($previous <= 0.0) {
            return null;
        }

        $percent = round(($current - $previous) / $previous * 100);

        return ['percent' => $percent, 'label' => $label];
    }

    /**
     * Příjmy (zaplacené vydané faktury) po měsících za vybraný kalendářní rok.
     *
     * @return array<int, array{label: string, value: float}>
     */
    private function monthlyIncome(int $year): array
    {
        $from = now()->setDate($year, 1, 1)->startOfDay();

        $sums = Invoice::query()
            ->where('direction', InvoiceDirection::Issued)
            ->where('status', InvoiceStatus::Paid)
            ->whereYear('paid_at', $year)
            ->get(['paid_at', 'total'])
            ->groupBy(fn (Invoice $invoice) => $invoice->paid_at->format('Y-m'))
            ->map(fn ($group) => $group->sum(fn (Invoice $invoice) => (float) $invoice->total));

        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $from->copy()->addMonths($i);
            $months[] = [
                'label' => $month->locale('cs')->isoFormat('MMM'),
                'value' => (float) ($sums[$month->format('Y-m')] ?? 0),
            ];
        }

        return $months;
    }
}
