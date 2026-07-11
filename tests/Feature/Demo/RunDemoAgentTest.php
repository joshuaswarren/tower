<?php

declare(strict_types=1);

use App\Enums\AgentStatus;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Events\Board\DemoOutputStreamed;
use App\Jobs\RunDemoAgent;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Event as EventModel;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use App\Support\Demo\DemoNarrator;
use App\Support\Demo\FakeNarrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * End-to-end acceptance for the demo loop
 * (docs/ARCHITECTURE.md §1.7 + the assignment contract).
 *
 *   - dispatching FleetAnalyst creates a demo run that goes
 *     queued -> running -> done on the board
 *   - emits >=2 DemoOutputStreamed chunks on demo.run.{id}
 *   - leaves an attested receipt with the report text
 *
 * Tests use FakeNarrator + sync queue + Event::fake where the
 * broadcast payload matters. The "real" path (LaravelAiNarrator) is
 * covered by StreamingContractTest and NarratorBindingTest.
 */
beforeEach(function (): void {
    Cache::flush();
    config()->set('tower.demo.enabled', true);
    config()->set('tower.demo.max_concurrent', 3);
    config()->set('tower.demo.rate_per_minute_per_ip', 3);
    config()->set('tower.demo.narrator', FakeNarrator::class);
    // Tests run with QUEUE_CONNECTION=sync (phpunit.xml), so dispatched
    // jobs run inline.
});

it('RunDemoAgent creates a kind=demo agent + token in the public demo workspace', function (): void {
    $externalId = 'demo:test:'.Str::lower(Str::random(10));

    RunDemoAgent::dispatch($externalId);

    $workspace = Workspace::query()->where('name', 'demo')->first();
    expect($workspace)->not->toBeNull();
    expect($workspace->visibility)->toBe(Workspace::VISIBILITY_PUBLIC);

    $agent = Agent::query()->where('workspace_id', $workspace->id)->first();
    expect($agent)->not->toBeNull();
    expect($agent->kind->value)->toBe('demo');
    expect($agent->name)->toBe('demo:fleet-analyst');

    $token = ApiToken::query()
        ->where('owner_type', Agent::class)
        ->where('owner_id', $agent->id)
        ->first();
    expect($token)->not->toBeNull();
    expect($token->abilities)->toContain(TokenAbility::Ingest->value);
    expect($token->abilities)->toContain(TokenAbility::Attest->value);
});

it('RunDemoAgent drives the run queued -> running -> done on the board', function (): void {
    Event::fake([DemoOutputStreamed::class]);

    $externalId = 'demo:test:'.Str::lower(Str::random(10));
    RunDemoAgent::dispatch($externalId);

    $run = Run::query()->where('external_id', $externalId)->firstOrFail();
    expect($run->state)->toBe(RunState::Done);
    expect($run->started_at)->not->toBeNull();
    expect($run->ended_at)->not->toBeNull();
    expect($run->duration_ms)->toBeGreaterThanOrEqual(0);

    // The agent's denormalized status lands on Done.
    $agent = Agent::query()->findOrFail($run->agent_id);
    expect($agent->status)->toBe(AgentStatus::Done);
});

it('RunDemoAgent emits >=2 DemoOutputStreamed chunks on demo.run.{id}', function (): void {
    Event::fake([DemoOutputStreamed::class]);

    $externalId = 'demo:test:'.Str::lower(Str::random(10));
    RunDemoAgent::dispatch($externalId);

    $run = Run::query()->where('external_id', $externalId)->firstOrFail();
    $events = Event::dispatched(DemoOutputStreamed::class);

    expect($events->count())->toBeGreaterThanOrEqual(2);

    // Every chunk is on the demo.run.{id} channel with the right name.
    foreach ($events as [$event]) {
        /** @var DemoOutputStreamed $event */
        expect($event->runId)->toBe($run->id);
        expect($event->broadcastAs())->toBe('demo.chunk');
        $channels = collect($event->broadcastOn())->map(fn ($c) => $c->name)->all();
        expect($channels)->toBe(['demo.run.'.$run->id]);
        expect($event->chunk)->toBeString();
        expect(strlen($event->chunk))->toBeGreaterThan(0);
    }

    // The chunks are sequentially numbered starting at 1.
    $seqs = $events->map(fn ($e) => $e[0]->seq)->all();
    expect($seqs)->toBe(range(1, $events->count()));
});

it('RunDemoAgent stores the assembled report on the run meta and as the receipt artifact', function (): void {
    $externalId = 'demo:test:'.Str::lower(Str::random(10));
    RunDemoAgent::dispatch($externalId);

    $run = Run::query()->where('external_id', $externalId)->firstOrFail();

    // Report is on the run meta for later retrieval.
    $meta = $run->meta ?? [];
    expect($meta)->toHaveKey('demo_report');
    expect($meta['demo_report'])->toBeString();
    expect(strlen($meta['demo_report']))->toBeGreaterThan(40);
    expect($meta)->toHaveKey('demo_narrator');
    expect($meta['demo_narrator'])->toBe(FakeNarrator::class);

    // Receipt is attested with the report as the summary (kind=text).
    $receipt = Receipt::query()->where('run_id', $run->id)->firstOrFail();
    expect($receipt->status)->toBe(ReceiptStatus::Attested);
    expect($receipt->kind)->toBe('text');
    expect($receipt->summary)->toBe($meta['demo_report']);
    expect($receipt->attested_by_type)->toBe(ApiToken::class);
    expect($receipt->attested_at)->not->toBeNull();

    // The honesty loop: a run.state_changed event row exists for each
    // transition (queued->running, running->done) — proof the demo
    // ran through the same path as a real producer.
    $stateEvents = EventModel::query()
        ->where('run_id', $run->id)
        ->where('type', 'run.state_changed')
        ->orderBy('id')
        ->get();
    $states = $stateEvents->pluck('to_state')->all();
    expect($states)->toContain('running');
    expect($states)->toContain('done');
});

it('Dispatching via the bound narrator writes the report onto the meta', function (): void {
    // Override the singleton with a custom fake so we can verify the
    // narrator that the job actually used (covers the binding path).
    $custom = new class implements DemoNarrator {
        public function stream(string $prompt, callable $onChunk): string
        {
            $onChunk('CUSTOM-');
            $onChunk('CHUNK-A');
            $onChunk('-CHUNK-B');
            return 'CUSTOM-CHUNK-A-CHUNK-B';
        }
    };
    $this->app->instance(DemoNarrator::class, $custom);

    Event::fake([DemoOutputStreamed::class]);

    $externalId = 'demo:test:'.Str::lower(Str::random(10));
    RunDemoAgent::dispatch($externalId);

    $run = Run::query()->where('external_id', $externalId)->firstOrFail();
    expect($run->meta['demo_report'])->toBe('CUSTOM-CHUNK-A-CHUNK-B');
    // The job sanitizes anonymous class names (NUL byte is jsonb-invalid).
    expect($run->meta['demo_narrator'])->toBe('anonymous');

    $events = Event::dispatched(DemoOutputStreamed::class);
    expect($events->count())->toBe(3);
    expect($events->map(fn ($e) => $e[0]->chunk)->all())->toBe(['CUSTOM-', 'CHUNK-A', '-CHUNK-B']);
});
