<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use App\Enums\HostKind;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\ApiToken;
use App\Models\DriftFlag;
use App\Models\Event;
use App\Models\Host;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * All 9 domain tables exist after a fresh migrate.
     */
    public function test_migrate_fresh_builds_every_domain_table(): void
    {
        $expected = [
            'hosts',
            'workspaces',
            'agents',
            'api_tokens',
            'runs',
            'events',
            'receipts',
            'allowlists',
            'drift_flags',
        ];

        foreach ($expected as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "table [{$table}] should exist after migrate:fresh"
            );
        }
    }

    /**
     * A representative row graph persists and every relationship resolves
     * the way ARCHITECTURE.md §1.2 says it should.
     */
    public function test_full_row_graph_persists_and_relationships_resolve(): void
    {
        $host = Host::factory()->herdr()->create(['name' => 'claude-a']);
        $workspace = Workspace::factory()->public()->onHost($host)->create(['name' => 'tower']);
        $agent = Agent::factory()->inWorkspace($workspace)->working()->create([
            'name' => 'omp-lane-acme',
            'kind' => AgentKind::Omp,
        ]);
        $run = Run::factory()->forAgent($agent)->running()->create([
            'external_id' => 'omp:acme:2026-07-11T13:55',
            'title' => 'ACME-241 checkout fix',
        ]);
        $event = Event::factory()->forRun($run)->runStateChanged('queued', 'running')->create([
            'occurred_at' => Carbon::parse('2026-07-11T14:02:09Z'),
            'dedupe_key' => 'omp:acme:2026-07-11T13:55:evt-00042',
            'payload' => ['reason' => 'awaiting approval', 'tools_used' => ['bash', 'edit']],
        ]);
        $receipt = Receipt::factory()->forRun($run)->attested('https://example.com/pr/42')->create();
        $allowlist = Allowlist::factory()->forAgent($agent)->version(2)->create([
            'manifest' => ['tools' => ['bash'], 'scopes' => ['repo:tower'], 'deny' => []],
        ]);
        $drift = DriftFlag::factory()->forAgent($agent)->violation()->create([
            'tool' => 'curl',
        ]);

        // Host -> workspaces / agents
        $this->assertCount(1, $host->workspaces);
        $this->assertTrue($host->workspaces->first()->is($workspace));
        $this->assertCount(1, $host->agents);

        // Workspace -> host, agents
        $this->assertTrue($workspace->host->is($host));
        $this->assertCount(1, $workspace->agents);
        $this->assertTrue($workspace->agents->first()->is($agent));

        // Workspace public scope returns only this row
        $this->assertSame(1, Workspace::public()->count());

        // Agent -> workspace/host/runs/events/allowlists/driftFlags
        $this->assertTrue($agent->workspace->is($workspace));
        $this->assertTrue($agent->host->is($host));
        $this->assertCount(1, $agent->runs);
        $this->assertCount(1, $agent->events);
        $this->assertCount(1, $agent->allowlists);
        $this->assertCount(1, $agent->driftFlags);

        // Run -> agent/events/receipts
        $this->assertTrue($run->agent->is($agent));
        $this->assertCount(1, $run->events);
        $this->assertCount(1, $run->receipts);

        // Event -> agent/run
        $this->assertTrue($event->agent->is($agent));
        $this->assertTrue($event->run->is($run));

        // Receipt -> run/agent + status
        $this->assertTrue($receipt->run->is($run));
        $this->assertTrue($receipt->agent->is($agent));
        $this->assertSame(ReceiptStatus::Attested, $receipt->status);

        // Allowlist -> agent
        $this->assertTrue($allowlist->agent->is($agent));

        // DriftFlag -> agent
        $this->assertSame(DriftSeverity::Violation, $drift->severity);
        $this->assertSame(DriftStatus::Open, $drift->status);
        $this->assertTrue($drift->agent->is($agent));
    }

    /**
     * Enums cast through Eloquent come back as the enum instance, not the
     * backing string. This guards the cast wiring.
     */
    public function test_enum_casts_round_trip_through_the_models(): void
    {
        $host = Host::factory()->create(['kind' => HostKind::Ci]);
        $agent = Agent::factory()->blocked()->create();
        $run = Run::factory()->done(durationMs: 12_000)->create();

        $this->assertSame(HostKind::Ci, $host->fresh()->kind);
        $this->assertSame(AgentStatus::Blocked, $agent->fresh()->status);
        $this->assertSame(RunState::Done, $run->fresh()->state);
        $this->assertSame(12_000, $run->fresh()->duration_ms);
    }

    /**
     * The state machine in ARCHITECTURE.md §1.2 is enforced by
     * RunState::canTransitionTo. Terminal states are final; self-transitions
     * are rejected; the documented legal moves all pass.
     */
    public function test_run_state_machine_transitions_match_the_spec(): void
    {
        $this->assertTrue(RunState::Queued->canTransitionTo(RunState::Running));
        $this->assertFalse(
            RunState::Queued->canTransitionTo(RunState::Done),
            'queued may only transition to running per §1.2'
        );

        $this->assertTrue(RunState::Running->canTransitionTo(RunState::Blocked));
        $this->assertTrue(RunState::Blocked->canTransitionTo(RunState::Running));
        $this->assertTrue(RunState::Running->canTransitionTo(RunState::Done));
        $this->assertTrue(RunState::Running->canTransitionTo(RunState::Failed));
        $this->assertTrue(RunState::Running->canTransitionTo(RunState::Abandoned));
        $this->assertTrue(RunState::Blocked->canTransitionTo(RunState::Done));
        $this->assertTrue(RunState::Blocked->canTransitionTo(RunState::Failed));
        $this->assertTrue(RunState::Blocked->canTransitionTo(RunState::Abandoned));

        // Self-transitions are rejected — no run.state_changed for running->running.
        $this->assertFalse(RunState::Running->canTransitionTo(RunState::Running));

        // Terminal states are final: no outbound edges, no self edges.
        foreach ([RunState::Done, RunState::Failed, RunState::Abandoned] as $terminal) {
            $this->assertTrue($terminal->isTerminal(), "{$terminal->value} should be terminal");
            foreach (RunState::cases() as $target) {
                $this->assertFalse(
                    $terminal->canTransitionTo($target),
                    "terminal state {$terminal->value} must not transition to {$target->value}"
                );
            }
        }

        // The headline illegal case: done -> running.
        $this->assertFalse(RunState::Done->canTransitionTo(RunState::Running));
    }

    /**
     * The `active` scope on Allowlist returns only the highest version per
     * agent. Inserting a higher version flips which row is active.
     */
    public function test_allowlist_active_scope_returns_highest_version_per_agent(): void
    {
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create();
        Allowlist::factory()->forAgent($agent)->version(2)->create();
        Allowlist::factory()->forAgent($agent)->version(3)->create();

        $active = Allowlist::active()->where('agent_id', $agent->id)->get();
        $this->assertCount(1, $active);
        $this->assertSame(3, $active->first()->version);

        // A second agent's allowlist is independent.
        $otherAgent = Agent::factory()->create();
        Allowlist::factory()->forAgent($otherAgent)->version(7)->create();
        $active = Allowlist::active()->where('agent_id', $otherAgent->id)->get();
        $this->assertCount(1, $active);
        $this->assertSame(7, $active->first()->version);
    }

    /**
     * `Agent::scopeStale` returns agents whose `last_heartbeat_at` is older
     * than `config('tower.staleness.offline_seconds')`. The threshold is
     * overridden so the test is deterministic regardless of defaults.
     */
    public function test_agent_stale_scope_filters_on_configured_threshold(): void
    {
        config()->set('tower.staleness.offline_seconds', 60);

        $fresh = Agent::factory()->heartbeatAt(now()->subSeconds(10))->create();
        $stale = Agent::factory()->heartbeatAt(now()->subSeconds(120))->create();
        $neverHeartbeated = Agent::factory()->heartbeatAt(null)->create();

        $staleIds = Agent::stale()->pluck('id')->all();

        $this->assertContains($stale->id, $staleIds);
        $this->assertNotContains($fresh->id, $staleIds, 'recent heartbeat is not stale');
        $this->assertNotContains(
            $neverHeartbeated->id,
            $staleIds,
            'agents with NULL heartbeat are not considered stale (sweep sets the first one)'
        );
    }

    /**
     * The events.dedupe_key partial unique index rejects a duplicate
     * non-null dedupe_key but allows multiple nulls.
     */
    public function test_events_dedupe_key_partial_unique_index_behaves_as_specified(): void
    {
        $agent = Agent::factory()->create();
        $key = 'dup-key-1';

        Event::factory()->forAgent($agent)
            ->withDedupeKey($key)->create();

        $this->expectException(QueryException::class);
        Event::factory()->forAgent($agent)
            ->withDedupeKey($key)->create();
    }

    public function test_events_dedupe_key_allows_multiple_nulls(): void
    {
        $agent = Agent::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Event::factory()->forAgent($agent)->create();
        }

        $this->assertSame(5, Event::whereNull('dedupe_key')->count());
    }

    /**
     * ApiToken's `abilities` jsonb column round-trips as an array, and the
     * token principal owner morph works for both Agent and Host. The morph
     * column is a ULID per the spec (token owner is an Agent or Host, not a
     * bigint user id), so the morph resolves across both.
     */
    public function test_api_token_abilities_cast_and_owner_morph_resolve(): void
    {
        $host = Host::factory()->create();
        $agent = Agent::factory()->create();

        $hostToken = ApiToken::factory()->forHost($host)
            ->withAbilities([TokenAbility::IngestHerdr])->create();
        $agentToken = ApiToken::factory()->forAgent($agent)
            ->withAbilities([TokenAbility::Ingest, TokenAbility::Attest])->create();

        $this->assertSame(['ingest:herdr'], $hostToken->fresh()->abilities);
        $this->assertSame(['ingest', 'attest'], $agentToken->fresh()->abilities);
        $this->assertTrue($hostToken->owner->is($host));
        $this->assertTrue($agentToken->owner->is($agent));
    }

    /**
     * `runs` enforces the (agent_id, external_id) uniqueness constraint.
     */
    public function test_runs_unique_per_agent_external_id(): void
    {
        $agent = Agent::factory()->create();
        Run::factory()->forAgent($agent)->create(['external_id' => 'run-x']);

        $this->expectException(QueryException::class);
        Run::factory()->forAgent($agent)->create(['external_id' => 'run-x']);
    }

    /**
     * Receipts.stub jsonb round-trips. Postgres jsonb normalizes key order,
     * so we assert by canonical key set + value equality.
     */
    public function test_receipt_stub_jsonb_round_trips(): void
    {
        $run = Run::factory()->create();
        $stub = [
            'agent' => 'omp-lane-acme',
            'workspace' => 'tower',
            'duration_ms' => 42_000,
            'exit_state' => 'success',
            'done_at' => now()->toIso8601String(),
        ];

        $receipt = Receipt::factory()->forRun($run)->create(['stub' => $stub]);

        $this->assertEqualsCanonicalizing(
            array_keys($stub),
            array_keys($receipt->fresh()->stub)
        );
        $this->assertEquals($stub, $receipt->fresh()->stub);
    }

    /**
     * Cascade rules: deleting an agent removes dependent runs/events/allowlists
     * and (via run) the receipts. Drift flags also cascade.
     */
    public function test_agent_cascade_removes_dependent_rows(): void
    {
        $agent = Agent::factory()->create();
        $run = Run::factory()->forAgent($agent)->create();
        Event::factory()->forRun($run)->create();
        Receipt::factory()->forRun($run)->create();
        Allowlist::factory()->forAgent($agent)->create();
        DriftFlag::factory()->forAgent($agent)->create();

        $agent->delete();

        $this->assertSame(0, Run::where('agent_id', $agent->id)->count());
        $this->assertSame(0, Receipt::where('agent_id', $agent->id)->count());
        $this->assertSame(0, Allowlist::where('agent_id', $agent->id)->count());
        $this->assertSame(0, DriftFlag::where('agent_id', $agent->id)->count());

        // Events with the now-orphaned agent_id should be gone via FK cascade.
        $this->assertSame(0, Event::where('agent_id', $agent->id)->count());
    }

    /**
     * The BRIN index on events.received_at is in place (raw statement in
     * migration). The test just confirms the index name is registered,
     * which is enough to catch a dropped index.
     */
    public function test_brin_index_on_events_received_at_is_registered(): void
    {
        $row = DB::selectOne(
            "select indexname from pg_indexes where indexname = 'events_received_at_brin'"
        );

        $this->assertNotNull($row, 'expected BRIN index events_received_at_brin to exist');
    }

    /**
     * The User model gained `attestedReceipts` (morphMany of receipts the
     * user has attested). The relation must resolve and round-trip.
     */
    public function test_user_attested_receipts_morph_relation_resolves(): void
    {
        $user = User::factory()->create();
        $run = Run::factory()->create();
        $receipt = Receipt::factory()->forRun($run)->attested()->create([
            'attested_by_type' => User::class,
            'attested_by_id' => $user->id,
        ]);

        $this->assertCount(1, $user->fresh()->attestedReceipts);
        $this->assertTrue($user->fresh()->attestedReceipts->first()->is($receipt));
    }
}
