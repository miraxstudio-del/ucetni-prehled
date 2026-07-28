<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InvoiceTemplate;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nastavení organizace: fakturační údaje, logo, razítko/podpis a vzhled
 * faktur (jen vlastník). Zobrazuje se sloučené s Fakturací na jedné stránce
 * — viz InvoicingController::show().
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeOwner();

        // Volba šablony má vlastní akci. Uživatel tak může změnit vzhled PDF
        // i tehdy, když ještě nemá doplněné ostatní fakturační údaje.
        if ($request->boolean('save_invoice_template')) {
            $validated = $request->validate([
                'invoice_template' => ['required', Rule::enum(InvoiceTemplate::class)],
            ], [], ['invoice_template' => 'vzhled faktury']);

            $this->context->current()->update($validated);
            $this->audit->log('organization.invoice_template_updated', entity: $this->context->current());

            return back()->with('status', 'Vzhled faktury byl uložen. Nově vystavené PDF použije zvolenou šablonu.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['nullable', 'digits:8'],
            'dic' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z]{2}[0-9A-Z]+$/'],
            'vat_payer' => ['boolean'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'registration_note' => ['nullable', 'string', 'max:255'],
            'default_due_days' => ['required', 'integer', 'min:1', 'max:365'],
            'invoice_footer' => ['nullable', 'string', 'max:1000'],
            'invoice_template' => ['required', Rule::enum(InvoiceTemplate::class)],
        ], [
            'ico.digits' => 'IČO musí mít přesně 8 číslic.',
            'dic.regex' => 'DIČ zadejte ve formátu CZ12345678.',
        ], [
            'name' => 'název', 'ico' => 'IČO', 'dic' => 'DIČ', 'email' => 'e-mail',
            'default_due_days' => 'splatnost', 'invoice_footer' => 'patička faktury',
            'invoice_template' => 'vzhled faktury',
        ]);

        $validated['vat_payer'] = (bool) ($validated['vat_payer'] ?? false);

        $this->context->current()->update($validated);

        $this->audit->log('organization.updated', entity: $this->context->current());

        return back()->with('status', 'Údaje organizace byly uloženy.');
    }

    /** Upload loga nebo razítka (PNG/JPG, max 1 MB) — tiskne se na faktury. */
    public function uploadImage(Request $request, string $type): RedirectResponse
    {
        $this->authorizeOwner();
        $field = $this->imageField($type);

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
        ], [], ['image' => 'obrázek']);

        $organization = $this->context->current();

        // starý soubor pryč, nový pod tenant složkou mimo webroot
        if ($organization->{$field}) {
            Storage::disk('local')->delete($organization->{$field});
        }

        $path = $request->file('image')->storeAs(
            'org/'.$organization->id,
            $type.'.'.$request->file('image')->extension(),
            'local',
        );

        $organization->update([$field => $path]);

        $this->audit->log('organization.'.$type.'_uploaded');

        return back()->with('status', $type === 'logo' ? 'Logo bylo nahráno.' : 'Razítko bylo nahráno.');
    }

    public function deleteImage(string $type): RedirectResponse
    {
        $this->authorizeOwner();
        $field = $this->imageField($type);

        $organization = $this->context->current();

        if ($organization->{$field}) {
            Storage::disk('local')->delete($organization->{$field});
            $organization->update([$field => null]);
        }

        $this->audit->log('organization.'.$type.'_removed');

        return back()->with('status', 'Obrázek byl odebrán.');
    }

    /** Náhled loga/razítka — soubor je mimo webroot, servíruje se jen členům organizace. */
    public function showImage(string $type): Response
    {
        $field = $this->imageField($type);
        $path = $this->context->current()->{$field};

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }

    private function imageField(string $type): string
    {
        return match ($type) {
            'logo' => 'logo_path',
            'razitko' => 'stamp_path',
            default => abort(404),
        };
    }

    private function authorizeOwner(): void
    {
        abort_unless(
            $this->context->role() === Role::Owner,
            403,
            'Údaje organizace může měnit jen vlastník.',
        );
    }
}
