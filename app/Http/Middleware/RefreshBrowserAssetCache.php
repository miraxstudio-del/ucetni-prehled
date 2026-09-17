<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jednorazove obnovi cache lokalniho prohlizece po aktualizaci Účetní přehled.
 *
 * Vydani 1.0.1 omylem poslalo CSS s MIME typem text/plain a Chrome si tuto
 * chybnou odpoved ulozil jako nemennou. Bez ohledu na opraveny server pak
 * muze z cache znovu pouzit neplatny stylopis. Prvni HTML pozadavek nove
 * verze proto bezpecne presmerujeme, vycistime pouze HTTP cache daneho
 * lokalniho originu a ulozime revizni cookie. Nasledujici pozadavek je uz
 * normalni a cache zustane rychla.
 */
class RefreshBrowserAssetCache
{
    /** Verejna proto, aby ji testy mohly poslat a neresily presmerovani. */
    public const REVISION = '2026-07-28-1';

    public const COOKIE = 'ucetni_prehled_asset_cache_revision';

    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->header('Accept', '');

        if (
            $request->isMethod('GET')
            && str_contains($accept, 'text/html')
            && $request->cookie(self::COOKIE) !== self::REVISION
        ) {
            $response = redirect()->to($request->fullUrl());
            $response->headers->set('Clear-Site-Data', '"cache"');

            // Cookie cte jen server, takze do JavaScriptu nepatri (httpOnly)
            // a nema duvod odejit u pozadavku, ktery zacal na cizim webu
            // (SameSite=Strict) — stejne jako cookie relace. Neni v ni nic
            // osobniho, jen cislo revize vydani.
            $response->headers->setCookie(Cookie::create(
                self::COOKIE,
                self::REVISION,
                now()->addYear(),
                '/',
                null,
                false,
                true,
                false,
                Cookie::SAMESITE_STRICT,
            ));

            return $response;
        }

        return $next($request);
    }
}
