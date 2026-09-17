<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propustí jen požadavky adresované na tento počítač.
 *
 * Vestavěný server naslouchá na 127.0.0.1, ale odpověděl by na jakoukoli
 * hlavičku Host. Toho lze zneužít přesměrováním cizí domény na 127.0.0.1
 * (DNS rebinding): prohlížeč pak stránky programu považuje za obsah té cizí
 * domény, takže si je její skript může přečíst — a s nimi i token CSRF, čímž
 * obejde i ochranu formulářů. Rozsah portů 8090–8099 je navíc krátký a snadno
 * uhodnutelný a program žádné přihlášení nemá (EstablishLocalPlatformContext
 * přihlašuje místní profil sám), takže každý požadavek, který na server
 * dorazí, má plná oprávnění.
 *
 * Seznam je krátký záměrně: co v něm není, se odmítne. START.bat otevírá
 * program vždy na http://127.0.0.1:<port>.
 */
class AllowLocalRequestsOnly
{
    /** @var array<int, string> */
    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtolower($request->getHost()), self::ALLOWED_HOSTS, true)) {
            return response(
                'Účetní přehled je místní program. Otevřete jej na adrese http://127.0.0.1 '
                    .'s portem, který vypsal START.bat.',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return $next($request);
    }
}
