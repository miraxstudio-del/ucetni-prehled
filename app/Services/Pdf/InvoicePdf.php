<?php

namespace App\Services\Pdf;

use App\Enums\InvoiceTemplate;
use App\Models\Invoice;
use App\Services\Invoicing\SpdPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

/**
 * Generování PDF faktury (dompdf) s QR platbou (SPD).
 * PDF se negeneruje do souboru — vzniká on-the-fly a servíruje se
 * výhradně přes autorizované podepsané URL.
 *
 * Stejná Blade šablona (podle zvoleného vzhledu v organizaci) se používá
 * i pro webový náhled — jedna definice vzhledu, ne dvě, co se časem rozejdou.
 */
class InvoicePdf
{
    public function __construct(
        private readonly SpdPayment $spd,
    ) {}

    public function render(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['items', 'client', 'bankAccount', 'organization']);

        // Vzhled (šablona) je prezentace — bere se vždy živý. Údaje na dokladu
        // naopak z okamžiku vystavení: freezeForDocument() podvrhne relace
        // snapshotem, takže šablona i QR platba čtou zmrazená data.
        $view = $this->view($invoice);
        $invoice->freezeForDocument();

        return Pdf::loadView($view, $this->data($invoice))->setPaper('a4');
    }

    /** Webový náhled — stejná šablona, jen vykreslená prohlížečem místo dompdf. */
    public function renderHtml(Invoice $invoice): string
    {
        $view = $this->view($invoice);
        $invoice->freezeForDocument();

        return view($view, $this->data($invoice))->render();
    }

    private function view(Invoice $invoice): string
    {
        $template = $invoice->organization->invoice_template ?? InvoiceTemplate::Klasik;

        return $template->view();
    }

    private function data(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'organization' => $invoice->organization,
            'qrDataUri' => $this->qrDataUri($invoice),
            'logoDataUri' => $this->imageDataUri($invoice->organization->logo_path),
            'stampDataUri' => $this->imageDataUri($invoice->organization->stamp_path),
        ];
    }

    /** Logo/razítko ze storage jako data URI (soubory jsou mimo webroot). */
    private function imageDataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
        $content = Storage::disk('local')->get($path);

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    public function filename(Invoice $invoice): string
    {
        $number = $invoice->number ?: 'koncept-'.$invoice->id;

        return 'faktura-'.str_replace(['/', '\\', ' '], '-', $number).'.pdf';
    }

    /** QR platba jako PNG data URI (GD), null pokud chybí účet. */
    private function qrDataUri(Invoice $invoice): ?string
    {
        $spd = $this->spd->build($invoice);

        if ($spd === null) {
            return null;
        }

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => 4,
            'outputBase64' => true,
        ]);

        return (new QRCode($options))->render($spd);
    }
}
