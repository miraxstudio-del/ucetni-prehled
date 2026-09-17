<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalHostGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_from_the_local_computer_pass(): void
    {
        foreach (['127.0.0.1', 'localhost'] as $host) {
            $this->get('http://'.$host.'/prehled')->assertOk();
        }
    }

    /**
     * Cizí doména přesměrovaná na 127.0.0.1 (DNS rebinding) by jinak stránky
     * programu dostala jako vlastní obsah a přečetla si z nich token CSRF.
     */
    public function test_requests_addressed_to_a_foreign_host_are_refused(): void
    {
        $this->get('http://ucetni-prehled.example/prehled')->assertForbidden();
    }

    /** Odmítnutí musí přijít dřív, než se cokoli uloží do databáze. */
    public function test_refused_request_does_not_create_the_local_profile(): void
    {
        $this->get('http://ucetni-prehled.example/prehled')->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_writes_from_a_foreign_host_are_refused(): void
    {
        $this->post('http://ucetni-prehled.example/klienti', ['name' => 'Pokus'])
            ->assertForbidden();

        $this->assertDatabaseCount('clients', 0);
    }
}
