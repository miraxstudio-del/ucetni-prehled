<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
{{--
    „Tradiční“ — věrná kopie vzhledu původních faktur Andrey (složky
    Účetnictví 2025/2026). Rozložení sejmuté ze souřadnic PDF: dvousloupcová
    hlavička Dodavatel × Vaše objednávka/Odběratel, blok Konečný příjemce,
    tabulka „Označení dodávky / Počet M.J. / M.J. / Cena za m.j. / Cena
    celkem“, „Celkem k platbě“ a tečkovaný řádek na razítko a podpis.
--}}
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; }
    .muted { color: #444; }
    .small { font-size: 8pt; }

    .header { width: 100%; margin-bottom: 4px; }
    .header td { vertical-align: bottom; }
    .doc-title { font-size: 12pt; font-weight: bold; }
    .doc-number { font-size: 12pt; font-weight: bold; text-align: right; }
    .header-rule { border-bottom: 1.5px solid #000; margin-bottom: 10px; }

    .cols { width: 100%; border-collapse: collapse; }
    .cols td { vertical-align: top; }
    .col-left { width: 51%; padding-right: 14px; }
    .col-right { width: 49%; }

    .block-label { font-weight: bold; margin-bottom: 4px; }
    .party-name { font-weight: bold; }
    .row { margin-bottom: 2px; }

    .kv { width: 100%; border-collapse: collapse; }
    .kv td { padding: 1px 0; }
    .kv .k { width: 46%; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.items th { text-align: left; font-weight: bold; padding: 4px 4px;
        border-top: 1px solid #000; border-bottom: 1px solid #000; }
    table.items td { padding: 4px 4px; }
    table.items tr.last td { border-bottom: 1px solid #000; }
    .num { text-align: right; white-space: nowrap; }

    .total-row { width: 100%; margin-top: 18px; }
    .total-label { font-size: 11pt; font-weight: bold; }
    .total-value { font-size: 11pt; font-weight: bold; text-align: right; }

    .note-block { margin-top: 12px; }
    .footer-notes { margin-top: 26px; }
    .footer-notes p { margin-bottom: 10px; }

    .sign { width: 100%; margin-top: 48px; }
    .sign td { vertical-align: bottom; }
    .sign .line { text-align: center; }
    .qr { text-align: left; }
    .qr img { width: 88px; height: 88px; }
</style>
</head>
<body>

@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $cityLine = fn ($city, $zip) => trim(implode(', ', array_filter([$city, $zip])), ', ');
@endphp

@if ($logoDataUri)
    <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 42px; max-width: 200px; margin-bottom: 10px;">
@endif

<table class="header">
    <tr>
        <td class="doc-title">{{ $invoice->type->documentTitle() }}</td>
        <td class="doc-number">{{ $invoice->number ?? 'KONCEPT' }}</td>
    </tr>
</table>
<div class="header-rule"></div>

<table class="cols">
    <tr>
        <td class="col-left">
            <div class="block-label">Dodavatel:</div>
            <div class="party-name row">{{ $organization->name }}</div>
            @if ($organization->street)<div class="row">{{ $organization->street }}</div>@endif
            @if ($organization->city)<div class="row">{{ $organization->zip }} {{ $organization->city }}</div>@endif

            <table class="kv" style="margin-top: 12px;">
                @if ($organization->phone)
                    <tr><td class="k">Mobil:</td><td>{{ $organization->phone }}</td></tr>
                @endif
                @if ($organization->email)
                    <tr><td class="k">e-mail:</td><td>{{ $organization->email }}</td></tr>
                @endif
                <tr><td class="k">www:</td><td>{{ $organization->website }}</td></tr>
                <tr><td class="k">IČO:</td><td>{{ $organization->ico }}</td></tr>
                <tr><td class="k">DIČ:</td><td>{{ $organization->vat_payer ? $organization->dic : 'NEPLÁTCE DPH' }}</td></tr>
                @if ($invoice->bankAccount)
                    <tr><td class="k">Účet:</td><td>{{ $invoice->bankAccount->displayNumber() }}</td></tr>
                @endif
            </table>

            <table class="kv" style="margin-top: 14px;">
                <tr><td class="k">Způsob platby:</td><td>{{ mb_strtolower($invoice->payment_method->label()) }}</td></tr>
                <tr><td class="k">Datum vystavení:</td><td>{{ $invoice->issue_date->format('d.m.Y') }}</td></tr>
                <tr><td class="k">Datum splatnosti:</td><td>{{ $invoice->due_date->format('d.m.Y') }}</td></tr>
                @if ($organization->vat_payer && $invoice->duzp)
                    <tr><td class="k">Datum uskut. zdaň. plnění:</td><td>{{ $invoice->duzp->format('d.m.Y') }}</td></tr>
                @endif
            </table>
        </td>
        <td class="col-right">
            <table class="kv">
                <tr><td class="k">Vaše objednávka:</td><td></td></tr>
                <tr><td class="k">Konstantní symbol:</td><td>{{ $invoice->payment_method === \App\Enums\PaymentMethod::BankTransfer ? '0008' : '' }}</td></tr>
                <tr><td class="k">Variabilní symbol:</td><td>{{ $invoice->variable_symbol }}</td></tr>
                <tr><td class="k">Specifický symbol:</td><td></td></tr>
            </table>

            <div class="block-label" style="margin-top: 10px;">Odběratel:</div>
            @if ($invoice->client)
                <div class="party-name row">{{ $invoice->client->name }}</div>
                @if ($invoice->client->street)<div class="row">{{ $invoice->client->street }}</div>@endif
                @if ($invoice->client->city || $invoice->client->zip)
                    <div class="row">{{ $cityLine($invoice->client->city, $invoice->client->zip) }}</div>
                @endif
            @endif

            <table class="kv" style="margin-top: 10px;">
                <tr><td class="k">IČO odběratele:</td><td>{{ $invoice->client?->ico }}</td></tr>
                <tr><td class="k">DIČ odběratele:</td><td>{{ $invoice->client?->dic }}</td></tr>
            </table>

            <div class="block-label" style="margin-top: 12px;">Konečný příjemce:</div>
            @if ($invoice->client)
                <div class="row">{{ $invoice->client->name }}</div>
                @if ($invoice->client->street)<div class="row">{{ $invoice->client->street }}</div>@endif
                @if ($invoice->client->city || $invoice->client->zip)
                    <div class="row">{{ $cityLine($invoice->client->city, $invoice->client->zip) }}</div>
                @endif
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Označení dodávky</th>
            <th class="num">Počet M.J.</th>
            <th>M.J.</th>
            @if ($organization->vat_payer)
                <th class="num">Cena za m.j.</th>
                <th class="num">DPH</th>
                <th class="num">Cena celkem</th>
            @else
                <th class="num">Cena za m.j.</th>
                <th class="num">Cena celkem</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr @class(['last' => $loop->last])>
                <td>{{ $item->description }}</td>
                <td class="num">{{ number_format((float) $item->quantity, 2, ',', ' ') }}</td>
                <td>{{ $item->unit }}</td>
                @if ($organization->vat_payer)
                    <td class="num">{{ $money($item->unit_price) }} Kč</td>
                    <td class="num">{{ number_format((float) $item->vat_rate, 0) }} %</td>
                    <td class="num">{{ $money($item->line_total) }} Kč</td>
                @else
                    <td class="num">{{ $money($item->unit_price) }} Kč</td>
                    <td class="num">{{ $money($item->line_total) }} Kč</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

@if ($invoice->note)
    <div class="note-block small">{{ $invoice->note }}</div>
@endif

<table class="total-row">
    <tr>
        <td style="width: 30%;"></td>
        <td class="total-label">Celkem k platbě:</td>
        <td class="total-value">{{ $money($invoice->total) }} {{ $invoice->currency === 'CZK' ? 'Kč' : $invoice->currency }}</td>
    </tr>
</table>

@if ($organization->vat_payer && $invoice->items->count())
    <table class="kv" style="margin-top: 8px; width: 45%; margin-left: 55%;">
        <tr><td class="k muted">Základ daně:</td><td class="num">{{ $money($invoice->subtotal) }} Kč</td></tr>
        @foreach ($invoice->vatBreakdown() as $rate => $amounts)
            <tr><td class="k muted">DPH {{ number_format((float) $rate, 0) }} %:</td><td class="num">{{ $money($amounts['vat']) }} Kč</td></tr>
        @endforeach
    </table>
@endif

<div class="footer-notes">
    @if ($organization->registration_note)
        <p>{{ $organization->registration_note }}@unless ($organization->vat_payer) Neplátce DPH.@endunless</p>
    @elseif (! $organization->vat_payer)
        <p>Neplátce DPH.</p>
    @endif
    @if ($organization->invoice_footer)
        <p>{{ $organization->invoice_footer }}</p>
    @endif
</div>

<table class="sign">
    <tr>
        <td style="width: 40%;">
            @if ($qrDataUri)
                <div class="qr">
                    <img src="{{ $qrDataUri }}" alt="QR platba">
                    <div class="small muted">QR platba</div>
                </div>
            @endif
        </td>
        <td style="width: 60%;" class="line">
            @if ($stampDataUri)
                <img src="{{ $stampDataUri }}" alt="Razítko a podpis" style="max-height: 70px; max-width: 190px;"><br>
            @endif
            <div>………………………...……</div>
            <div class="small muted">razítko, podpis dodavatele</div>
        </td>
    </tr>
</table>

</body>
</html>
