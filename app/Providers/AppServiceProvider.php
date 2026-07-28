<?php

namespace App\Providers;

use App\Support\OrganizationContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Context lives for one local request and scopes all accounting data.
        $this->app->scoped(OrganizationContext::class);
    }

    public function boot(): void
    {
        // No remote account, login, registration, or authentication throttling.
    }
}
