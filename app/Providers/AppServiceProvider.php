<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Demo\DemoNarrator;
use App\Support\Demo\FakeNarrator;
use App\Support\Demo\LaravelAiNarrator;
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
        // DemoNarrator binding (docs/ARCHITECTURE.md §1.7 + §1.8).
        //
        // We pick the REAL `LaravelAiNarrator` (first-party Laravel AI SDK,
        // which wraps `prism-php/prism` internally) when ANY provider key
        // is configured. The key check is a literal `filled(...)` against
        // `config('ai.providers.*.key')` so a fork can swap the key by
        // env (OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY / ...).
        //
        // When no key is set the binding falls back to `FakeNarrator` —
        // a deterministic offline-only fake. The fallback is documented
        // in the class docblock; this provider does NOT advertise the
        // fake as the demo.
        $this->app->singleton(DemoNarrator::class, function ($app): DemoNarrator {
            $providerKeys = (array) config('ai.providers', []);
            $hasKey = false;
            foreach ($providerKeys as $config) {
                if (is_array($config) && filled($config['key'] ?? null)) {
                    $hasKey = true;
                    break;
                }
            }

            // Tests may explicitly pin which one to use via config.
            $pin = config('tower.demo.narrator');
            if ($pin === LaravelAiNarrator::class) {
                return $app->make(LaravelAiNarrator::class);
            }
            if ($pin === FakeNarrator::class) {
                return $app->make(FakeNarrator::class);
            }

            return $hasKey
                ? $app->make(LaravelAiNarrator::class)
                : $app->make(FakeNarrator::class);
        });
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

        // Per-IP demo dispatch rate limit. Keyed by client IP, backed by
        // the `database` cache store in production (or `array` in tests).
        // The demo console is gated by this limit; over the cap returns a
        // flash error and the dispatch is not enqueued.
        RateLimiter::for('demo-dispatch', function (Request $request): Limit {
            $perMinute = (int) config('tower.demo.rate_per_minute_per_ip', 3);
            return Limit::perMinute($perMinute)->by('demo-dispatch:'.(string) $request->ip());
        });
    }
}
