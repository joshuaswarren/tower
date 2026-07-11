<?php

declare(strict_types=1);

use App\Enums\AgentStatus;
use App\Enums\EventType;
use App\Enums\RunState;
use App\Events\Board\AgentStatusChanged;
use App\Events\Board\RunUpdated;
use App\Jobs\ProcessIngestedBatch;
use App\Models\Agent;
use App\Models\Event;
use App\Models\Host;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event as EventFacade;

// Regression guard: ProcessIngestedBatch must broadcast the EXISTING event
// instances (via broadcast()), not re-construct them. The prior bug used
// `$event::dispatch($event)` which silently threw and delivered nothing —
// this test fails against that bug because the recorded instance would carry
// the wrong (object-as-string) constructor args or never dispatch.

it('broadcasts RunUpdated and AgentStatusChanged with correct payloads for a public workspace', function (): void {
    EventFacade::fake([RunUpdated::class, AgentStatusChanged::class]);

    $host = Host::factory()->create();
    $ws = Workspace::factory()->onHost($host)->public()->create();
    $agent = Agent::factory()->inWorkspace($ws)->create(['status' => AgentStatus::Working]);
    $run = Run::factory()->forAgent($agent)->create(['state' => RunState::Running]);

    $event = Event::factory()->forAgent($agent)->create([
        'run_id' => $run->id,
        'type' => EventType::RunStateChanged,
        'from_state' => RunState::Queued->value,
        'to_state' => RunState::Running->value,
    ]);

    (new ProcessIngestedBatch([$event->id]))->handle(
        app(App\Actions\Drift\DetectDrift::class),
        app(App\Actions\Receipts\CreateReceiptStub::class),
    );

    EventFacade::assertDispatched(RunUpdated::class, function (RunUpdated $e) use ($run, $agent): bool {
        return $e->runId === (string) $run->id
            && $e->agentId === (string) $agent->id
            && $e->state === RunState::Running
            && $e->workspacePublic === true;
    });

    EventFacade::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) use ($agent): bool {
        return $e->agentId === (string) $agent->id
            && $e->status === AgentStatus::Working
            && $e->workspacePublic === true;
    });
});

it('marks broadcasts non-public for a private workspace', function (): void {
    EventFacade::fake([AgentStatusChanged::class]);

    $host = Host::factory()->create();
    $ws = Workspace::factory()->onHost($host)->create(); // private by default
    $agent = Agent::factory()->inWorkspace($ws)->create(['status' => AgentStatus::Blocked]);
    $run = Run::factory()->forAgent($agent)->create(['state' => RunState::Blocked]);
    $event = Event::factory()->forAgent($agent)->create([
        'run_id' => $run->id,
        'type' => EventType::RunStateChanged,
        'to_state' => RunState::Blocked->value,
    ]);

    (new ProcessIngestedBatch([$event->id]))->handle(
        app(App\Actions\Drift\DetectDrift::class),
        app(App\Actions\Receipts\CreateReceiptStub::class),
    );

    EventFacade::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e): bool {
        return $e->workspacePublic === false;
    });
});
