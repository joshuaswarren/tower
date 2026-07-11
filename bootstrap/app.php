<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureTokenAbility;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withCommands([
        // Discover Tower's artisan commands under app/Console/Commands.
        // Laravel 13 does not auto-discover that directory.
        __DIR__.'/../app/Console/Commands',
    ])
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        // Deny guests at the HTTP boundary regardless of broadcast driver:
        // subscribing to a private channel requires an authenticated session.
        // Public channels (public.board, demo.run.*) never hit /broadcasting/auth.
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'token' => AuthenticateApiToken::class,
            'ability' => EnsureTokenAbility::class,
        ]);

        // The per-token `ingest` rate limit is registered in
        // App\Providers\AppServiceProvider::boot() because the Facade root
        // is not yet resolvable from inside the withMiddleware callback.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
