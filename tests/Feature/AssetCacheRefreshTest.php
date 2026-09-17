<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshBrowserAssetCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Jednorázové obnovení cache prohlížeče po aktualizaci. Základní TestCase
 * posílá revizní cookie všem testům, aby se stránky neodpovídaly 302 — tady
 * se proto ověřuje ta cesta bez ní.
 */
class AssetCacheRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_page_without_the_revision_cookie_is_refreshed_once(): void
    {
        $response = $this->withoutTheRevisionCookie()->get('/prehled');

        $response->assertRedirect('/prehled');
        $response->assertHeader('Clear-Site-Data', '"cache"');
    }

    public function test_page_with_the_revision_cookie_is_served_directly(): void
    {
        $this->get('/prehled')->assertOk();
    }

    /** Cookie čte jen server a nemá opouštět vlastní původ. */
    public function test_revision_cookie_is_http_only_and_same_site_strict(): void
    {
        $cookie = collect($this->withoutTheRevisionCookie()->get('/prehled')->headers->getCookies())
            ->first(fn ($c) => $c->getName() === RefreshBrowserAssetCache::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, strtolower((string) $cookie->getSameSite()));
    }

    private function withoutTheRevisionCookie(): self
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];

        return $this;
    }
}
