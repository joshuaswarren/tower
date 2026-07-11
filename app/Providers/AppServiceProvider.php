<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-token ingest rate limit. Keyed by the api_tokens.id (set on the
        // request as `tower_token_id` by AuthenticateApiToken) so each token
        // gets its own bucket. Falls back to client IP for unauthenticated
        // requests, which will 401 anyway — the bucket just keeps abuse from
        // saturating the database lookup.
        RateLimiter::for('ingest', function (Request $request): Limit {
            $perMinute = (int) config('tower.ingest.rate_per_minute', 120);
            $tokenId = $request->attributes->get('tower_token_id');
            $key = $tokenId !== null
                ? 'token:'.$tokenId
                : 'ip:'.(string) $request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });
    }
}
