<!DOCTYPE html>
<html lang="cs">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 24px;">
    <p style="font-size: 20px; font-weight: bold; color: #4f46e5;">Účetní přehled</p>

    <p>Dobrý den,</p>

    <p><strong>{{ $inviterName }}</strong> vás zve do organizace
        <strong>{{ $organizationName }}</strong> ve fakturačním systému Účetní přehled
        v roli <strong>{{ $role->label() }}</strong>@if($role === App\Enums\Role::Accountant)
        (přístup pouze pro čtení a exporty)@endif.</p>

    <p style="margin: 24px 0;">
        <a href="{{ $acceptUrl }}"
           style="background: #4f46e5; color: #ffffff; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;">
            Přijmout pozvánku
        </a>
    </p>

    <p style="font-size: 13px; color: #64748b;">
        Pozvánka platí 7 dní. Pokud ještě nemáte účet, zaregistrujte se
        s e-mailovou adresou, na kterou pozvánka přišla, a poté odkaz otevřete znovu.
    </p>

    <p style="font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 24px;">
        Účetní přehled · www.miraxstudio.cz — pokud pozvánku nečekáte, tento e-mail ignorujte.
    </p>
</body>
</html>
