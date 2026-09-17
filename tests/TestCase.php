<?php

namespace Tests;

use App\Enums\Role;
use App\Http\Middleware\RefreshBrowserAssetCache;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testy nepotřebují Vite build (manifest nemusí existovat)
        $this->withoutVite();

        // RefreshBrowserAssetCache přesměruje první HTML požadavek prohlížeče,
        // dokud nemá revizní cookie. V testech by tak každá stránka odpovídala
        // 302 místo 200, takže cookii posíláme rovnou. Samotné přesměrování
        // ověřuje AssetCacheRefreshTest.
        $this->withCookie(
            RefreshBrowserAssetCache::COOKIE,
            RefreshBrowserAssetCache::REVISION,
        );
    }

    /** Vytvoří ověřeného uživatele s organizací a členstvím v dané roli. */
    protected function createUserWithOrganization(Role $role = Role::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => $role,
        ]);

        return [$user, $organization];
    }
}
