<?php

declare(strict_types=1);

use App\Support\Demo\DemoNarrator;
use App\Support\Demo\FakeNarrator;
use App\Support\Demo\LaravelAiNarrator;

/**
 * Narrator binding test — the parent agent's directive:
 *
 *   "assert the binding resolves to Prism vs Fake based on a config key
 *    in a test that sets/unsets it"
 *
 * (In the actual implementation the SDK is `laravel/ai` v0.9.0, the
 * first-party Laravel AI SDK which internally wraps `prism-php/prism`.
 * The contract is the same: the real class is `LaravelAiNarrator`.)
 *
 * The binding decision lives in `AppServiceProvider::register`:
 *
 *   - Any non-empty `config('ai.providers.*.key')` value  -> LaravelAiNarrator
 *   - Otherwise                                         -> FakeNarrator
 *   - A test pin via `config('tower.demo.narrator')` overrides either
 *     way so a single test can force the binding deterministically.
 */
beforeEach(function (): void {
    // Forget the singleton so each test re-resolves the binding.
    $this->app->forgetInstance(DemoNarrator::class);
    // Start with a clean slate: no provider key, no pin.
    config()->set('ai.providers', []);
    config()->set('tower.demo.narrator', null);
});

it('binds FakeNarrator when no provider key is configured (offline-only fallback)', function (): void {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => null],
        'anthropic' => ['driver' => 'anthropic', 'key' => ''],
    ]);

    $bound = app(DemoNarrator::class);
    expect($bound)->toBeInstanceOf(FakeNarrator::class);
});

it('binds LaravelAiNarrator when a provider key is configured (real path)', function (): void {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-test-12345'],
    ]);

    $bound = app(DemoNarrator::class);
    expect($bound)->toBeInstanceOf(LaravelAiNarrator::class);
});

it('switching the key on/off flips the binding (config is the source of truth)', function (): void {
    // Start with the real path.
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-original'],
    ]);
    expect(app(DemoNarrator::class))->toBeInstanceOf(LaravelAiNarrator::class);

    // Drop the key, re-resolve.
    $this->app->forgetInstance(DemoNarrator::class);
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => null],
    ]);
    expect(app(DemoNarrator::class))->toBeInstanceOf(FakeNarrator::class);

    // Bring the key back, re-resolve.
    $this->app->forgetInstance(DemoNarrator::class);
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-restored'],
    ]);
    expect(app(DemoNarrator::class))->toBeInstanceOf(LaravelAiNarrator::class);
});

it('test pin via tower.demo.narrator forces a specific implementation', function (): void {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-exists'],
    ]);

    // No pin: real path
    expect(app(DemoNarrator::class))->toBeInstanceOf(LaravelAiNarrator::class);

    // Pin to fake despite the key being present.
    $this->app->forgetInstance(DemoNarrator::class);
    config()->set('tower.demo.narrator', FakeNarrator::class);
    expect(app(DemoNarrator::class))->toBeInstanceOf(FakeNarrator::class);

    // Pin back to real.
    $this->app->forgetInstance(DemoNarrator::class);
    config()->set('tower.demo.narrator', LaravelAiNarrator::class);
    expect(app(DemoNarrator::class))->toBeInstanceOf(LaravelAiNarrator::class);
});

it('LaravelAiNarrator class exists and is autoloadable (real path is wired)', function (): void {
    expect(class_exists(LaravelAiNarrator::class))->toBeTrue();
    $reflect = new ReflectionClass(LaravelAiNarrator::class);
    expect($reflect->implementsInterface(DemoNarrator::class))->toBeTrue();
    // Constructor takes an optional factory closure.
    expect($reflect->getConstructor()?->getNumberOfParameters())->toBeLessThanOrEqual(1);
});
