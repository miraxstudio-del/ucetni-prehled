<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/prehled');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_csp_does_not_allow_inline_scripts(): void
    {
        $csp = $this->get('/prehled')->headers->get('Content-Security-Policy');

        // Injektovaný <script> se nesmí spustit ani při případném XSS
        $scriptSrc = collect(explode(';', (string) $csp))
            ->map(fn ($part) => trim($part))
            ->first(fn ($part) => str_starts_with($part, 'script-src'));

        $this->assertNotNull($scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringContainsString("'self'", $scriptSrc);
    }

    /**
     * Pojistka proti regresi: CSP inline skripty blokuje, takže by se
     * nespustily. Dřív tak tiše nefungoval formulář faktury i potvrzení
     * u mazání — JS musí být výhradně v sestavovaném bundlu.
     */
    public function test_no_view_contains_inline_scripts_or_handlers(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $name = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);

            if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $contents)) {
                $offenders[] = $name.' — inline <script> blok';
            }

            if (preg_match('/\son(click|submit|change|load|error)\s*=/i', $contents)) {
                $offenders[] = $name.' — inline on* handler';
            }
        }

        $this->assertSame([], $offenders, "CSP tyhle skripty zablokuje:\n".implode("\n", $offenders));
    }

    /**
     * Blade escapuje {{ }} automaticky; {!! !!} tuhle ochranu vypíná a je to
     * nejčastější cesta k XSS. Pojistka: v šablonách nemá co dělat.
     */
    public function test_no_view_outputs_unescaped_html(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (str_contains((string) file_get_contents($file), '{!!')) {
                $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $offenders, "Neescapovaný výstup {!! !!} v:\n".implode("\n", $offenders));
    }

    public function test_session_cookie_is_http_only(): void
    {
        $response = $this->get('/prehled');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_destructive_actions_ask_for_confirmation(): void
    {
        [$user, $organization] = $this->createUserWithOrganization();
        app(OrganizationContext::class)->forceSet($organization);

        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('data-confirm', false);
    }

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
