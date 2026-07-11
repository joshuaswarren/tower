<?php

declare(strict_types=1);

use App\Enums\EventType;
use App\Models\Agent;
use App\Models\Event;
use App\Models\Host;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

function seedRun(): Run
{
    $host = Host::factory()->create();
    $ws = Workspace::factory()->onHost($host)->create();
    $agent = Agent::factory()->inWorkspace($ws)->create();

    return Run::factory()->forAgent($agent)->create();
}

it('renders the event timeline in chronological order', function (): void {
    $run = seedRun();
    foreach (['first-marker', 'second-marker', 'third-marker'] as $i => $note) {
        Event::factory()->forAgent($run->agent)->create([
            'run_id' => $run->id,
            'type' => EventType::LogNote,
            'payload' => ['note' => $note],
            'occurred_at' => now()->addSeconds($i),
        ]);
    }

    actingAs(User::factory()->create())
        ->get(route('runs.show', $run))
        ->assertOk()
        ->assertSeeInOrder(['first-marker', 'second-marker', 'third-marker']);
});

it('renders an attested receipt as a linked chip', function (): void {
    $run = seedRun();
    Receipt::factory()->forRun($run)->attested('https://example.com/pr/42')->create();

    actingAs(User::factory()->create())
        ->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('data-receipt-status="attested"', false)
        ->assertSee('https://example.com/pr/42');
});

it('renders an unattested receipt as a non-linked stub chip', function (): void {
    $run = seedRun();
    Receipt::factory()->forRun($run)->create(['url' => null]);

    actingAs(User::factory()->create())
        ->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('data-receipt-status="unattested"', false);
});
