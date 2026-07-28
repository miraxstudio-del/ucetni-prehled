<!DOCTYPE html>
<html lang="cs">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 24px;">
    <p>Dobrý den,</p>

    <p>dovolujeme si připomenout, že faktura <strong>{{ $invoice->number }}</strong>
        na částku <strong>{{ number_format((float) $invoice->total, 2, ',', ' ') }} {{ $invoice->currency }}</strong>
        byla splatná <strong>{{ $invoice->due_date->format('j. n. Y') }}</strong>
        a dosud nemáme evidovanou její úhradu.</p>

    <table style="border-collapse: collapse; margin: 16px 0; font-size: 14px;">
        @if ($invoice->variable_symbol)
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Variabilní symbol</td><td>{{ $invoice->variable_symbol }}</td></tr>
        @endif
        @if ($invoice->bankAccount)
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Číslo účtu</td><td>{{ $invoice->bankAccount->displayNumber() }}</td></tr>
        @endif
    </table>

    <p>Pokud jste platbu již odeslali, považujte prosím tento e-mail za bezpředmětný.</p>

    <p>S pozdravem<br>{{ $invoice->organization->name }}</p>
</body>
</html>
