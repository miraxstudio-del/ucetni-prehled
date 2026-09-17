<?php

use App\Http\Middleware\AllowLocalRequestsOnly;
use App\Http\Middleware\EstablishLocalPlatformContext;
use App\Http\Middleware\RefreshBrowserAssetCache;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Musí běžet dřív než cokoli dalšího: EstablishLocalPlatformContext
        // požadavek rovnou přihlásí a zapisuje do databáze, takže cizí Host
        // je potřeba odmítnout ještě před ním.
        $middleware->prepend(AllowLocalRequestsOnly::class);

        $middleware->web(append: [
            EstablishLocalPlatformContext::class,
            RefreshBrowserAssetCache::class,
            SecurityHeaders::class,
        ]);

        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EstablishLocalPlatformContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
