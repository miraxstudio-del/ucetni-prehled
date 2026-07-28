<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111827; }
    .muted { color: #6b7280; }
    .small { font-size: 8pt; }

    .header { width: 100%; margin-bottom: 10px; }
    .header td { vertical-align: bottom; }
    .doc-title { font-size: 9pt; text-transform: uppercase; letter-spacing: 2px; color: #6b7280; }
    .doc-number { font-size: 16pt; font-weight: bold; margin-top: 2px; }
    .header-rule { border-bottom: 1px solid #111827; margin-bottom: 20px; }

    .parties { width: 100%; margin-bottom: 18px; }
    .parties td { width: 50%; vertical-align: top; padding-right: 24px; }
    .party-label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1.5px; color: #9ca3af; margin-bottom: 5px; }
    .party-name { font-size: 10.5pt; font-weight: bold; margin-bottom: 2px; }
    .rule { border-bottom: 1px solid #e5e7eb; margin: 16px 0; }

    .meta { width: 100%; margin-bottom: 18px; }
    .meta td { padding-right: 26px; font-size: 9pt; }
    .meta .label { color: #9ca3af; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; }
    .meta .value { font-weight: bold; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px;
        color: #9ca3af; font-weight: normal; padding: 5px 6px; border-bottom: 1px solid #111827; }
    table.items td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; }
    .num { text-align: right; white-space: nowrap; }

    .totals { width: 40%; margin-left: 60%; }
    .totals td { padding: 3px 0; }
    .totals .grand td { border-top: 1px solid #111827; font-size: 11.5pt; font-weight: bold; padding-top: 8px; }

    .qr { text-align: left; margin-top: 16px; }
    .qr img { width: 84px; height: 84px; }

    .vat-note { margin: 14px 0; padding: 8px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7.5pt; color: #9ca3af;
        border-top: 1px solid #e5e7eb; padding-top: 6px; }
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
                <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 38px; max-width: 180px;">
            @endif
        </td>
        <td style="text-align: right;">
            <div class="doc-title">{{ $invoice->type->documentTitle() }}</div>
            <div class="doc-number">{{ $invoice->number ?? 'KONCEPT' }}</div>
        </td>
    </tr>
</table>
<div class="header-rule"></div>

<table class="parties">
    <tr>
        <td>
            <div class="party-label">Dodavatel</div>
            <div class="party-name">{{ $organization->name }}</div>
            @if ($organization->street)<div>{{ $organization->street }}</div>@endif
            @if ($organization->city)<div>{{ $organization->zip }} {{ $organization->city }}</div>@endif
            <div class="small muted" style="margin-top: 4px;">
                @if ($organization->ico)IČ {{ $organization->ico }}@endif
                @if ($organization->dic) &nbsp; DIČ {{ $organization->dic }}@endif
            </div>
            @unless ($organization->vat_payer)
                <div class="small" style="margin-top: 4px;">Nejsem plátce DPH.</div>
            @endunless
        </td>
        <td>
            <div class="party-label">Odběratel</div>
            @if ($invoice->client)
                <div class="party-name">{{ $invoice->client->name }}</div>
                @if ($invoice->client->street)<div>{{ $invoice->client->street }}</div>@endif
                @if ($invoice->client->city)<div>{{ $invoice->client->zip }} {{ $invoice->client->city }}</div>@endif
                <div class="small muted" style="margin-top: 4px;">
                    @if ($invoice->client->ico)IČ {{ $invoice->client->ico }}@endif
                    @if ($invoice->client->dic) &nbsp; DIČ {{ $invoice->client->dic }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="meta">
    <tr>
        <td><div class="label">Vystaveno</div><div class="value">{{ $invoice->issue_date->format('j. n. Y') }}</div></td>
        @if ($organization->vat_payer && $invoice->duzp)
            <td><div class="label">DUZP</div><div class="value">{{ $invoice->duzp->format('j. n. Y') }}</div></td>
        @endif
        <td><div class="label">Splatnost</div><div class="value">{{ $invoice->due_date->format('j. n. Y') }}</div></td>
        <td><div class="label">Úhrada</div><div class="value">{{ $invoice->payment_method->label() }}</div></td>
        @if ($invoice->variable_symbol)
            <td><div class="label">VS</div><div class="value">{{ $invoice->variable_symbol }}</div></td>
        @endif
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Popis</th>
            <th class="num">Množství</th>
            @if ($organization->vat_payer)
                <th class="num">Cena/MJ</th>
                <th class="num">DPH</th>
                <th class="num">Celkem</th>
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
                <td class="muted">DPH {{ number_format((float) $rate, 0) }} %</td>
                <td class="num">{{ $money($amounts['vat']) }} {{ $invoice->currency }}</td>
            </tr>
        @endforeach
    @endif
    <tr class="grand">
        <td>Celkem</td>
        <td class="num">{{ $money($invoice->total) }} {{ $invoice->currency }}</td>
    </tr>
</table>

@if ($qrDataUri)
    <div class="qr">
        <img src="{{ $qrDataUri }}" alt="QR platba">
        <div class="small muted">QR platba</div>
    </div>
@endif

@if ($stampDataUri)
    <table style="width: 100%; margin-top: 10px;">
        <tr>
            <td></td>
            <td style="width: 40%; text-align: center;">
                <img src="{{ $stampDataUri }}" alt="Razítko a podpis" style="max-height: 70px; max-width: 180px;">
            </td>
        </tr>
    </table>
@endif

@if ($invoice->note)
    <div class="vat-note small">{{ $invoice->note }}</div>
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
