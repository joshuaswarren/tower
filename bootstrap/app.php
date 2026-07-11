<?php

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
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        // Deny guests at the HTTP boundary regardless of broadcast driver:
        // subscribing to a private channel requires an authenticated session.
        // Public channels (public.board, demo.run.*) never hit /broadcasting/auth.
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lane A registers the `token` alias (AuthenticateApiToken) and the
        // `throttle:ingest` limiter here.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
