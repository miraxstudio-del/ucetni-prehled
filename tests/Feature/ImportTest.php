<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_csv_import_creates_clients_and_reports_errors(): void
    {
        [$user] = $this->createUserWithOrganization();

        $csv = "nazev;ico;dic;ulice;mesto;psc;email;telefon;splatnost_dny;poznamka\n"
            ."Acme s.r.o.;12345678;;Dlouhá 1;Praha;11000;info@acme.cz;;14;\n"
            .";99999999;;;;;;;;\n"           // chybí název → chyba
            ."Beta a.s.;1234;;;;;;;;\n";     // špatné IČO → chyba

        $this->actingAs($user)->post(route('accounting.import.clients'), [
            'file' => UploadedFile::fake()->createWithContent('klienti.csv', $csv),
        ])->assertRedirect()->assertSessionHas('importErrors');

        $this->assertSame(1, Client::acrossAllOrganizations()->count());
        $imported = Client::acrossAllOrganizations()->whereName('Acme s.r.o.')->firstOrFail();
        $this->assertSame('12345678', $imported->ico);

        // opakovaný import stejného souboru → duplicita se přeskočí
        $this->actingAs($user)->post(route('accounting.import.clients'), [
            'file' => UploadedFile::fake()->createWithContent('klienti.csv', $csv),
        ]);

        $this->assertSame(1, Client::acrossAllOrganizations()->count());
    }

    public function test_invoices_csv_import_creates_invoices_with_statuses(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $csv = "cislo;smer;klient;ico;vystaveno;splatnost;zaplaceno;popis;castka_bez_dph;sazba_dph;mena\n"
            ."2025-0001;vydana;Acme s.r.o.;12345678;15.01.2025;29.01.2025;28.01.2025;Vývoj webu;25000;0;CZK\n"
            ."FP-889;prijata;Dodavatel a.s.;87654321;20.01.2025;03.02.2025;;Licence;4500;21;CZK\n";

        $this->actingAs($user)->post(route('accounting.import.invoices'), [
            'file' => UploadedFile::fake()->createWithContent('faktury.csv', $csv),
        ])->assertRedirect();

        $issued = Invoice::where('number', '2025-0001')->firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $issued->status);
        $this->assertSame('25000.00', (string) $issued->total);
        $this->assertSame('issued', $issued->direction->value);

        $received = Invoice::where('number', 'FP-889')->firstOrFail();
        $this->assertSame(InvoiceStatus::Issued, $received->status);
        $this->assertSame('received', $received->direction->value);
        $this->assertSame('5445.00', (string) $received->total); // 4500 + 21 % DPH

        // klienti se založili automaticky (IČO je šifrované → přes slepý index)
        $this->assertNotNull(Client::whereIco('12345678')->first());

        // duplicitní čísla se přeskočí
        $this->actingAs($user)->post(route('accounting.import.invoices'), [
            'file' => UploadedFile::fake()->createWithContent('faktury.csv', $csv),
        ]);

        $this->assertSame(2, Invoice::count());
    }

    public function test_templates_are_downloadable(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->get(route('accounting.template.clients'))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="sablona-klienti.csv"');

        $this->actingAs($user)->get(route('accounting.template.invoices'))
            ->assertOk();
    }

    public function test_accountant_cannot_import(): void
    {
        [$user] = $this->createUserWithOrganization(Role::Accountant);

        $this->actingAs($user)->post(route('accounting.import.clients'), [
            'file' => UploadedFile::fake()->createWithContent('klienti.csv', 'nazev;ico'),
        ])->assertForbidden();
    }

    public function test_accounting_page_renders(): void
    {
        [$user] = $this->createUserWithOrganization();

        $this->actingAs($user)->get(route('accounting.index'))
            ->assertOk()
            ->assertSee('Kompletní balíček pro účetní');
    }
}
