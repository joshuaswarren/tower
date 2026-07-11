<?php

declare(strict_types=1);

use App\Livewire\DemoConsole;
use Livewire\Livewire;

/**
 * Feature flag test — the demo is cut-safe by design
 * (docs/ARCHITECTURE.md §1.7).
 *
 *   - With `tower.demo.enabled=false` the dispatch route 404s.
 *   - With `tower.demo.enabled=false` the console is absent.
 */
it('demo route redirects to login when tower.demo.enabled is false', function (): void {
    config()->set('tower.demo.enabled', false);

    // The route always exists (its name resolves), but the console redirects
    // to login when the flag is off — same UX as the disabled public board.
    $this->get('/demo')->assertRedirect(route('login'));
});

it('demo route is present when tower.demo.enabled is true', function (): void {
    config()->set('tower.demo.enabled', true);

    $this->get('/demo')->assertOk();
});

it('DemoConsole Livewire component redirects to login when feature is off', function (): void {
    config()->set('tower.demo.enabled', false);

    // Even if the component is mounted directly, it must redirect.
    Livewire::test(DemoConsole::class)
        ->assertRedirect(route('login'));
});

it('DemoConsole Livewire component renders when feature is on', function (): void {
    config()->set('tower.demo.enabled', true);
    config()->set('tower.demo.narrator', \App\Support\Demo\FakeNarrator::class);

    Livewire::test(DemoConsole::class)
        ->assertSet('activeRunId', null)
        ->assertSet('streamedText', '');
});

it('demo route uses the demo.console name (frozen contract)', function (): void {
    config()->set('tower.demo.enabled', true);
    expect(route('demo.console'))->toBe(url('/demo'));
});
