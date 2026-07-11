<?php

declare(strict_types=1);

use App\Enums\AgentStatus;
use App\Enums\ReceiptStatus;
use App\Models\Agent;
use App\Models\DriftFlag;
use App\Models\Receipt;
use App\Models\Workspace;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoWorkspaceSeeder;

it('refuses to seed the admin in production without credentials', function (): void {
    app()['env'] = 'production';
    config(['tower.admin.email' => null, 'tower.admin.password' => null]);

    expect(fn () => (new AdminUserSeeder())->run())
        ->toThrow(RuntimeException::class);
});

it('seeds an admin when credentials are configured', function (): void {
    config(['tower.admin.email' => 'admin@tower.test', 'tower.admin.password' => 'secret-pw']);

    (new AdminUserSeeder())->run();

    $this->assertDatabaseHas('users', ['email' => 'admin@tower.test']);
});

it('seeds a public demo workspace with all board states', function (): void {
    (new DemoWorkspaceSeeder())->run();

    $ws = Workspace::query()->where('name', 'demo')->firstOrFail();
    expect($ws->visibility->value ?? $ws->visibility)->toBe(Workspace::VISIBILITY_PUBLIC);

    $statuses = Agent::query()->where('workspace_id', $ws->id)->pluck('status')->map(
        fn ($s) => $s instanceof AgentStatus ? $s->value : $s
    )->unique();
    expect($statuses)->toContain('blocked')->toContain('working')->toContain('done');

    expect(DriftFlag::query()->where('status', 'open')->count())->toBeGreaterThan(0);
    expect(Receipt::query()->where('status', ReceiptStatus::Attested->value)->count())->toBeGreaterThan(0);
    expect(Receipt::query()->where('status', ReceiptStatus::Unattested->value)->count())->toBeGreaterThan(0);
});

it('does not seed a public demo workspace on the default path when demo is disabled', function (): void {
    config([
        'tower.demo.enabled' => false,
        'tower.admin.email' => 'admin@tower.test',
        'tower.admin.password' => 'secret-pw',
    ]);

    (new DatabaseSeeder())->run();

    expect(Workspace::query()->where('name', 'demo')->exists())->toBeFalse();
    expect(Agent::query()->where('kind', 'demo')->exists())->toBeFalse();
    $this->assertDatabaseHas('users', ['email' => 'admin@tower.test']);
});

it('seeds the demo workspace on the default path when demo is enabled', function (): void {
    config([
        'tower.demo.enabled' => true,
        'tower.admin.email' => 'admin@tower.test',
        'tower.admin.password' => 'secret-pw',
    ]);

    (new DatabaseSeeder())->run();

    expect(Workspace::query()->where('name', 'demo')->exists())->toBeTrue();
});

it('is idempotent — running the demo seeder twice does not duplicate rows', function (): void {
    (new DemoWorkspaceSeeder())->run();
    $ws = Workspace::query()->where('name', 'demo')->firstOrFail();
    $agentsAfterFirst = Agent::query()->where('workspace_id', $ws->id)->count();
    $eventsAfterFirst = \App\Models\Event::query()->count();

    (new DemoWorkspaceSeeder())->run();

    expect(Agent::query()->where('workspace_id', $ws->id)->count())->toBe($agentsAfterFirst)
        ->and(\App\Models\Event::query()->count())->toBe($eventsAfterFirst);
});
