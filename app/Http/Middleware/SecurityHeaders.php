<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Cizí stránka si nesmí vzít nic z aplikace ani jako obrázek či soubor
        // (CORP), ani si držet odkaz na její okno (COOP). U místního programu,
        // který nemá přihlášení, je to hlavní obrana proti tomu, aby si na něj
        // web otevřený ve stejném prohlížeči vůbec dosáhl.
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // X-XSS-Protection tu schválně není: filtr, který zapínala, prohlížeče
        // odstranily a v některých verzích sám tvořil zranitelnost. Tuhle roli
        // plní CSP níž.

        // CSP se vynechává jen při běžícím Vite dev serveru (hot reload)
        if (! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if (app()->environment('production') && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        // Vědomé ústupky (obojí je defense-in-depth, ne díra):
        //
        // 'unsafe-eval' — Alpine vyhodnocuje výrazy v atributech přes
        //   new Function(). Zneužitelné je to teprve tehdy, když útočník už
        //   dokáže do stránky propašovat řetězec, tj. přes XSS — a tomu brání
        //   automatické escapování v Blade. Odstranění by znamenalo build
        //   @alpinejs/csp a přepis formuláře faktury (nepovoluje výrazy jako
        //   items.length > 1), což je riziko na nejdůležitější obrazovce
        //   výměnou za minimální zisk.
        //
        // style-src 'unsafe-inline' — Alpine (x-show) i graf na přehledu
        //   nastavují style="…". Útoky přes CSS jsou řádově méně závažné.
        //
        // Skripty naopak inline NEJSOU vůbec: žádný <script> blok ani on*
        // handler v šablonách (hlídá SecurityHeadersTest).
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
