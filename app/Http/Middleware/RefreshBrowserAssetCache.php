<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

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
    private const REVISION = '2026-07-28-1';

    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->header('Accept', '');

        if (
            $request->isMethod('GET')
            && str_contains($accept, 'text/html')
            && $request->cookie('ucetni_prehled_asset_cache_revision') !== self::REVISION
        ) {
            $response = redirect()->to($request->fullUrl());
            $response->headers->set('Clear-Site-Data', '"cache"');
            $response->headers->setCookie(Cookie::create(
                'ucetni_prehled_asset_cache_revision',
                self::REVISION,
                now()->addYear(),
                '/',
                null,
                false,
                false,
                false,
                Cookie::SAMESITE_LAX,
            ));

            return $response;
        }

        return $next($request);
    }
}
