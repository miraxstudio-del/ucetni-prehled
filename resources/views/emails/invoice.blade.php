<!DOCTYPE html>
<html lang="cs">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 24px;">
    <p>Dobrý den,</p>

    @if ($customMessage)
        <p style="white-space: pre-line;">{{ $customMessage }}</p>
    @else
        <p>v příloze zasíláme {{ mb_strtolower($invoice->type->label()) }}
            <strong>{{ $invoice->number }}</strong>
            na částku <strong>{{ number_format((float) $invoice->total, 2, ',', ' ') }} Kč</strong>
            se splatností <strong>{{ $invoice->due_date->format('j. n. Y') }}</strong>.</p>
    @endif

    <table style="border-collapse: collapse; margin: 16px 0; font-size: 14px;">
        <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Číslo dokladu</td><td>{{ $invoice->number }}</td></tr>
        @if ($invoice->variable_symbol)
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Variabilní symbol</td><td>{{ $invoice->variable_symbol }}</td></tr>
        @endif
        @if ($invoice->bankAccount)
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Číslo účtu</td><td>{{ $invoice->bankAccount->displayNumber() }}</td></tr>
        @endif
        <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Částka</td><td><strong>{{ number_format((float) $invoice->total, 2, ',', ' ') }} {{ $invoice->currency }}</strong></td></tr>
        <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Splatnost</td><td>{{ $invoice->due_date->format('j. n. Y') }}</td></tr>
    </table>

    <p>S pozdravem<br>{{ $invoice->organization->name }}</p>

    <p style="font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 24px;">
        Faktura byla vystavena v systému Účetní přehled · www.miraxstudio.cz
    </p>
</body>
</html>
