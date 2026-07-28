<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1e293b; }
    .header { width: 100%; margin-bottom: 24px; }
    .header td { vertical-align: top; }
    .doc-title { font-size: 17pt; font-weight: bold; color: #0f172a; }
    .doc-number { font-size: 13pt; color: #4f46e5; font-weight: bold; margin-top: 2px; }
    .muted { color: #64748b; }
    .small { font-size: 8pt; }
    .parties { width: 100%; margin-bottom: 20px; }
    .parties td { width: 50%; vertical-align: top; padding-right: 20px; }
    .party-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 6px; }
    .party-name { font-size: 11pt; font-weight: bold; margin-bottom: 3px; }
    .meta { width: 100%; margin-bottom: 20px; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
    .meta td { padding: 7px 10px 7px 0; }
    .meta .label { color: #64748b; font-size: 8pt; }
    .meta .value { font-weight: bold; font-size: 10pt; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5px;
        color: #64748b; padding: 6px 8px; border-bottom: 2px solid #0f172a; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .totals { width: 45%; margin-left: 55%; border-collapse: collapse; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { border-top: 2px solid #0f172a; font-size: 12pt; font-weight: bold; padding-top: 8px; }
    .vat-note { margin: 14px 0; padding: 8px 12px; background: #f8fafc; border-left: 3px solid #4f46e5; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7.5pt; color: #94a3b8;
        border-top: 1px solid #e2e8f0; padding-top: 6px; }
    .qr { text-align: center; }
    .qr img { width: 110px; height: 110px; }
</style>
</head>
<body>

@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp

<table class="header">
    <tr>
        <td>
            @if ($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 46px; max-width: 220px; margin-bottom: 8px;">
            @endif
            <div class="doc-title">{{ $invoice->type->documentTitle() }}</div>
            <div class="doc-number">{{ $invoice->number ?? 'KONCEPT' }}</div>
            @if ($invoice->type === \App\Enums\DocumentType::CreditNote && $invoice->correctedInvoice)
                <div class="muted small" style="margin-top:4px;">k faktuře č. {{ $invoice->correctedInvoice->number }}</div>
            @endif
        </td>
        <td style="text-align: right;">
            @if ($qrDataUri)
                <div class="qr">
                    <img src="{{ $qrDataUri }}" alt="QR platba">
                    <div class="small muted">QR platba</div>
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="party-label">Dodavatel</div>
            <div class="party-name">{{ $organization->name }}</div>
            @if ($organization->street)<div>{{ $organization->street }}</div>@endif
            @if ($organization->city)<div>{{ $organization->zip }} {{ $organization->city }}</div>@endif
            <div style="margin-top: 5px;">
                @if ($organization->ico)IČO: {{ $organization->ico }}@endif
                @if ($organization->dic) · DIČ: {{ $organization->dic }}@endif
            </div>
            @if ($organization->registration_note)
                <div class="small muted" style="margin-top: 3px;">{{ $organization->registration_note }}</div>
            @endif
            @unless ($organization->vat_payer)
                <div style="margin-top: 5px; font-weight: bold;">Nejsem plátce DPH.</div>
            @endunless
        </td>
        <td>
            <div class="party-label">Odběratel</div>
            @if ($invoice->client)
                <div class="party-name">{{ $invoice->client->name }}</div>
                @if ($invoice->client->street)<div>{{ $invoice->client->street }}</div>@endif
                @if ($invoice->client->city)<div>{{ $invoice->client->zip }} {{ $invoice->client->city }}</div>@endif
                <div style="margin-top: 5px;">
                    @if ($invoice->client->ico)IČO: {{ $invoice->client->ico }}@endif
                    @if ($invoice->client->dic) · DIČ: {{ $invoice->client->dic }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><div class="label">Datum vystavení</div><div class="value">{{ $invoice->issue_date->format('j. n. Y') }}</div></td>
        @if ($organization->vat_payer && $invoice->duzp)
            <td><div class="label">DUZP</div><div class="value">{{ $invoice->duzp->format('j. n. Y') }}</div></td>
        @endif
        <td><div class="label">Splatnost</div><div class="value">{{ $invoice->due_date->format('j. n. Y') }}</div></td>
        <td><div class="label">Způsob úhrady</div><div class="value">{{ $invoice->payment_method->label() }}</div></td>
        @if ($invoice->bankAccount)
            <td><div class="label">Číslo účtu</div><div class="value">{{ $invoice->bankAccount->displayNumber() }}</div></td>
        @endif
        @if ($invoice->variable_symbol)
            <td><div class="label">Variabilní symbol</div><div class="value">{{ $invoice->variable_symbol }}</div></td>
        @endif
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Popis</th>
            <th class="num">Množství</th>
            @if ($organization->vat_payer)
                <th class="num">Cena/MJ bez DPH</th>
                <th class="num">DPH</th>
                <th class="num">Celkem s DPH</th>
            @else
                <th class="num">Cena/MJ</th>
                <th class="num">Celkem</th>
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
                    <td class="num">{{ $money($item->line_total) }}</td>
                @else
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    @if ($organization->vat_payer)
        <tr><td class="muted">Základ daně</td><td class="num">{{ $money($invoice->subtotal) }} {{ $invoice->currency }}</td></tr>
        @foreach ($invoice->vatBreakdown() as $rate => $amounts)
            <tr>
                <td class="muted">DPH {{ number_format((float) $rate, 0) }} % ze {{ $money($amounts['base']) }}</td>
                <td class="num">{{ $money($amounts['vat']) }} {{ $invoice->currency }}</td>
            </tr>
        @endforeach
    @endif
    <tr class="grand">
        <td>Celkem k úhradě</td>
        <td class="num">{{ $money($invoice->total) }} {{ $invoice->currency }}</td>
    </tr>
</table>

@if ($stampDataUri)
    <table style="width: 100%; margin-top: 8px;">
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
    {{ $organization->name }}@if($organization->ico) · IČO: {{ $organization->ico }}@endif
    · Vystaveno v systému Účetní přehled (www.miraxstudio.cz)
</div>

</body>
</html>
