<?php

declare(strict_types=1);

use App\Livewire\FleetBoard;
use App\Models\Agent;
use App\Models\Host;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

function seedHostWithWorkspace(bool $public = false): Workspace
{
    $host = Host::factory()->create();
    $factory = Workspace::factory()->onHost($host);

    return $public ? $factory->public()->create() : $factory->create();
}

it('renders agents across every status on the authed board', function (): void {
    $ws = seedHostWithWorkspace();
    $names = [];
    foreach (['idle', 'working', 'blocked', 'done', 'offline'] as $status) {
        $agent = Agent::factory()->inWorkspace($ws)->create([
            'name' => "agent-{$status}-marker",
            'status' => $status,
        ]);
        $names[] = $agent->name;
    }

    $response = actingAs(User::factory()->create())->get(route('board'))->assertOk();

    foreach ($names as $name) {
        $response->assertSee($name);
    }
});

it('lists the oldest-blocked agent first in the attention island', function (): void {
    $ws = seedHostWithWorkspace();
    $older = Agent::factory()->inWorkspace($ws)->blocked()->create([
        'name' => 'older-blocked', 'last_event_at' => now()->subHours(3),
    ]);
    $newer = Agent::factory()->inWorkspace($ws)->blocked()->create([
        'name' => 'newer-blocked', 'last_event_at' => now()->subMinutes(5),
    ]);

    $items = Livewire::actingAs(User::factory()->create())
        ->test(FleetBoard::class)
        ->instance()
        ->attentionItems();

    $labels = $items->pluck('label')->all();
    expect(array_search('older-blocked', $labels, true))
        ->toBeLessThan(array_search('newer-blocked', $labels, true));
    expect($items->first()['label'])->toBe('older-blocked');
});

it('never renders private-workspace identifiers on the public board', function (): void {
    $private = seedHostWithWorkspace(public: false);
    $public = seedHostWithWorkspace(public: true);
    Agent::factory()->inWorkspace($private)->create(['name' => 'privagentxyz']);
    Agent::factory()->inWorkspace($public)->create(['name' => 'pubagentxyz']);

    // Public `/` hides the private agent, shows the public one.
    get('/')->assertOk()
        ->assertDontSee('privagentxyz')
        ->assertSee('pubagentxyz');

    // Authed `/board` sees everything.
    actingAs(User::factory()->create())->get(route('board'))
        ->assertSee('privagentxyz')
        ->assertSee('pubagentxyz');
});

it('redirects the public board to login when the feature is disabled', function (): void {
    config(['tower.public_board.enabled' => false]);

    get('/')->assertRedirect(route('login'));
});

it('redirects a guest away from the authed board', function (): void {
    get(route('board'))->assertRedirect(route('login'));
});
