<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1e293b; }
    .accent { color: #4f46e5; }
    .muted { color: #64748b; }
    .small { font-size: 8pt; }

    .header { width: 100%; margin-bottom: 14px; }
    .header td { vertical-align: top; }
    .doc-title { font-size: 12pt; font-weight: bold; color: #4f46e5; text-align: right; text-transform: uppercase; letter-spacing: 0.5px; }
    .doc-number { font-size: 22pt; font-weight: bold; color: #0f172a; text-align: right; margin-top: 2px; }
    .header-rule { border-bottom: 3px solid #4f46e5; margin-bottom: 18px; }

    .parties { width: 100%; margin-bottom: 16px; border-collapse: separate; border-spacing: 10px 0; margin-left: -10px; width: calc(100% + 20px); }
    .party-box { width: 50%; vertical-align: top; background: #f8fafc; border-radius: 6px; padding: 12px 14px; }
    .party-label { font-size: 9pt; font-weight: bold; color: #0f172a; margin-bottom: 8px; }
    .party-name { font-size: 12pt; font-weight: bold; margin-bottom: 3px; }

    .meta { width: 100%; margin-bottom: 16px; border-collapse: collapse; }
    .meta td { padding: 6px 12px 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 9pt; }
    .meta .label { color: #64748b; }
    .meta .value { font-weight: bold; float: right; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items thead td { background: #4f46e5; color: #fff; font-size: 8pt; text-transform: uppercase;
        letter-spacing: 0.5px; padding: 8px; font-weight: bold; }
    table.items thead td:first-child { border-radius: 4px 0 0 4px; }
    table.items thead td:last-child { border-radius: 0 4px 4px 0; }
    table.items tbody td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    table.items tbody tr:nth-child(even) td { background: #f8fafc; }
    .num { text-align: right; white-space: nowrap; }

    .bottom { width: 100%; margin-top: 10px; }
    .bottom td { vertical-align: top; }
    .qr-box { text-align: center; }
    .qr-box img { width: 100px; height: 100px; }
    .bank-box { background: #f8fafc; border-radius: 6px; padding: 10px 14px; }
    .bank-box .label { font-size: 8pt; font-weight: bold; color: #4f46e5; text-transform: uppercase; margin-bottom: 6px; }

    .totals { width: 100%; border-collapse: collapse; }
    .totals td { padding: 3px 0; font-size: 9.5pt; }
    .totals .num { text-align: right; }
    .totals .grand { background: #4f46e5; color: #fff; margin-top: 8px; }
    .totals .grand td { padding: 10px 12px; font-size: 13pt; font-weight: bold; }

    .vat-note { margin: 14px 0; padding: 10px 14px; background: #f8fafc; border-radius: 6px; border-left: 4px solid #4f46e5; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7.5pt; color: #94a3b8;
        border-top: 1px solid #e2e8f0; padding-top: 6px; text-align: center; }
</style>
</head>
<body>

@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp

<table class="header">
    <tr>
        <td style="width: 60%;">
            @if ($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 48px; max-width: 220px;">
            @endif
        </td>
        <td style="width: 40%;">
            <div class="doc-title">{{ $invoice->type->documentTitle() }}</div>
            <div class="doc-number">{{ $invoice->number ?? 'KONCEPT' }}</div>
            @if ($invoice->type === \App\Enums\DocumentType::CreditNote && $invoice->correctedInvoice)
                <div class="muted small" style="text-align: right; margin-top:4px;">k faktuře č. {{ $invoice->correctedInvoice->number }}</div>
            @endif
        </td>
    </tr>
</table>
<div class="header-rule"></div>

<table class="parties">
    <tr>
        <td class="party-box">
            <div class="party-label">DODAVATEL</div>
            <div class="party-name">{{ $organization->name }}</div>
            @if ($organization->street)<div>{{ $organization->street }}</div>@endif
            @if ($organization->city)<div>{{ $organization->zip }} {{ $organization->city }}</div>@endif
            <div>Česká republika</div>
            <div style="margin-top: 6px;">
                @if ($organization->ico)IČ: {{ $organization->ico }}@endif
                @if ($organization->dic)<br>DIČ: {{ $organization->dic }}@endif
            </div>
            @if ($organization->registration_note)
                <div class="small muted" style="margin-top: 4px;">{{ $organization->registration_note }}</div>
            @endif
            @unless ($organization->vat_payer)
                <div style="margin-top: 6px; font-weight: bold;">Nejsem plátce DPH.</div>
            @endunless
        </td>
        <td class="party-box">
            <div class="party-label accent">ODBĚRATEL</div>
            @if ($invoice->client)
                <div class="party-name">{{ $invoice->client->name }}</div>
                @if ($invoice->client->street)<div>{{ $invoice->client->street }}</div>@endif
                @if ($invoice->client->city)<div>{{ $invoice->client->zip }} {{ $invoice->client->city }}</div>@endif
                <div>Česká republika</div>
                <div style="margin-top: 6px;">
                    @if ($invoice->client->ico)IČ: {{ $invoice->client->ico }}@endif
                    @if ($invoice->client->dic)<br>DIČ: {{ $invoice->client->dic }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><span class="label">Vystaveno</span> <span class="value">{{ $invoice->issue_date->format('j. n. Y') }}</span></td>
        <td>
            <span class="label">Var. symbol</span> <span class="value">{{ $invoice->variable_symbol ?? $invoice->number }}</span>
        </td>
    </tr>
    <tr>
        @if ($organization->vat_payer && $invoice->duzp)
            <td><span class="label">DUZP</span> <span class="value">{{ $invoice->duzp->format('j. n. Y') }}</span></td>
        @else
            <td></td>
        @endif
        <td><span class="label">Měna</span> <span class="value">{{ $invoice->currency }}</span></td>
    </tr>
    <tr>
        <td><span class="label">Splatnost</span> <span class="value">{{ $invoice->due_date->format('j. n. Y') }}</span></td>
        <td><span class="label">Úhrada</span> <span class="value">{{ $invoice->payment_method->label() }}</span></td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <td>Popis</td>
            <td class="num">Mn.</td>
            @if ($organization->vat_payer)
                <td class="num">Cena/j</td>
                <td class="num">DPH</td>
                <td class="num">Bez DPH</td>
                <td class="num">S DPH</td>
            @else
                <td class="num">Cena/j</td>
                <td class="num">Celkem</td>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, ',', ' '), '0'), ',') }} {{ $item->unit }}</td>
                @if ($organization->vat_payer)
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ number_format((float) $item->vat_rate, 0) }} %</td>
                    <td class="num">{{ $money($item->line_subtotal) }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                @else
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

<table class="bottom">
    <tr>
        <td style="width: 22%;">
            @if ($qrDataUri)
                <div class="qr-box">
                    <img src="{{ $qrDataUri }}" alt="QR platba">
                    <div class="small muted">QR Pay</div>
                </div>
            @endif
        </td>
        <td style="width: 38%; padding-right: 14px;">
            @if ($invoice->bankAccount)
                <div class="bank-box">
                    <div class="label">Bankovní spojení</div>
                    <div style="font-weight: bold;">{{ $invoice->bankAccount->displayNumber() }}</div>
                    <div class="small muted" style="margin-top: 4px;">{{ $invoice->bankAccount->name }}</div>
                </div>
            @endif
        </td>
        <td style="width: 40%;">
            <table class="totals">
                @if ($organization->vat_payer)
                    <tr><td class="muted">Základ</td><td class="num">{{ $money($invoice->subtotal) }} {{ $invoice->currency }}</td></tr>
                    @foreach ($invoice->vatBreakdown() as $rate => $amounts)
                        <tr>
                            <td class="muted">DPH {{ number_format((float) $rate, 0) }} %</td>
                            <td class="num">{{ $money($amounts['vat']) }} {{ $invoice->currency }}</td>
                        </tr>
                    @endforeach
                @endif
                <tr class="grand"><td>Celkem</td><td class="num">{{ $money($invoice->total) }} {{ $invoice->currency }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($stampDataUri)
    <table style="width: 100%; margin-top: 12px;">
        <tr>
            <td></td>
            <td style="width: 45%; text-align: center;">
                <img src="{{ $stampDataUri }}" alt="Razítko a podpis" style="max-height: 80px; max-width: 200px;">
                <div class="small muted">razítko a podpis</div>
            </td>
        </tr>
    </table>
@endif

@if ($invoice->note)
    <div class="vat-note">{{ $invoice->note }}</div>
@endif

@if ($organization->invoice_footer)
    <div class="muted small" style="margin-top: 10px;">{{ $organization->invoice_footer }}</div>
@endif

<div class="footer">
    {{ $organization->name }}@if($organization->email) · {{ $organization->email }}@endif
    · Vystaveno v systému Účetní přehled (www.miraxstudio.cz)
</div>

</body>
</html>
