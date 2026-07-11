<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Ingest\ApplyRunTransition;
use App\Actions\Ingest\RecordEvents;
use App\Actions\Receipts\AttestReceipt;
use App\Actions\Receipts\CreateReceiptStub;
use App\Enums\AgentStatus;
use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\DriftFlag;
use App\Models\Event;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // ApplyRunTransition
    // -----------------------------------------------------------------

    public function test_legal_transition_advances_state_and_sets_started_at(): void
    {
        $agent = Agent::factory()->create();
        $run = Run::factory()->forAgent($agent)->queued()->create([
            'started_at' => null,
        ]);

        $result = (new ApplyRunTransition())->execute(
            $run,
            RunState::Queued,
            RunState::Running,
            null,
            \Carbon\CarbonImmutable::parse('2026-07-11T10:00:00Z'),
        );
        $run->refresh();

        $this->assertFalse($result['rejected']);
        $this->assertSame(RunState::Running, $run->state);
        $this->assertNotNull($run->started_at);
    }

    public function test_terminal_transition_computes_duration_and_exit_state(): void
    {
        $agent = Agent::factory()->create();
        $run = Run::factory()->forAgent($agent)->create([
            'state' => RunState::Running,
            'started_at' => \Carbon\CarbonImmutable::parse('2026-07-11T10:00:00Z'),
            'ended_at' => null,
            'duration_ms' => null,
        ]);

        $result = (new ApplyRunTransition())->execute(
            $run,
            RunState::Running,
            RunState::Done,
            'success',
            \Carbon\CarbonImmutable::parse('2026-07-11T10:00:30Z'),
        );
        $run->refresh();

        $this->assertFalse($result['rejected']);
        $this->assertSame(RunState::Done, $run->state);
        $this->assertSame(30_000, $run->duration_ms);
        $this->assertSame('success', $run->exit_state);
        $this->assertNotNull($run->ended_at);
    }

    public function test_run_state_to_agent_status_mapping(): void
    {
        $action = new ApplyRunTransition();
        $this->assertSame(AgentStatus::Working, $action->mapRunStateToAgentStatus(RunState::Running));
        $this->assertSame(AgentStatus::Blocked, $action->mapRunStateToAgentStatus(RunState::Blocked));
        $this->assertSame(AgentStatus::Done, $action->mapRunStateToAgentStatus(RunState::Done));
        $this->assertSame(AgentStatus::Idle, $action->mapRunStateToAgentStatus(RunState::Queued));
    }

    // -----------------------------------------------------------------
    // RecordEvents
    // -----------------------------------------------------------------

    public function test_record_events_inserts_dedupes_and_rejects_partial(): void
    {
        $agent = Agent::factory()->create();

        $r = (new RecordEvents(new ApplyRunTransition()))->execute($agent, [
            'schema' => 'tower.ingest.v1',
            'events' => [
                ['type' => 'log.note', 'dedupe_key' => 'a'],
                ['type' => 'log.note', 'dedupe_key' => 'a'],
                ['type' => 'log.note'],
            ],
        ]);

        $this->assertSame(2, $r['accepted']);
        $this->assertSame(1, $r['duplicates']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_record_events_marks_agent_status_from_run_transition(): void
    {
        $agent = Agent::factory()->create();
        (new RecordEvents(new ApplyRunTransition()))->execute($agent, [
            'schema' => 'tower.ingest.v1',
            'events' => [
                [
                    'type' => 'run.state_changed',
                    'run' => ['external_id' => 'r-1'],
                    'to' => 'running',
                    'from' => 'queued',
                ],
            ],
        ]);

        $agent->refresh();
        $this->assertSame(AgentStatus::Working, $agent->status);
    }

    // -----------------------------------------------------------------
    // CreateReceiptStub
    // -----------------------------------------------------------------

    public function test_create_stub_is_idempotent(): void
    {
        $run = Run::factory()->done(12_000)->create();
        $r1 = (new CreateReceiptStub())->execute($run);
        $r2 = (new CreateReceiptStub())->execute($run);

        $this->assertTrue($r1['created']);
        $this->assertFalse($r2['created']);
        $this->assertSame($r1['receipt']->id, $r2['receipt']->id);
        $this->assertSame(12_000, $r1['receipt']->stub['duration_ms']);
    }

    // -----------------------------------------------------------------
    // AttestReceipt
    // -----------------------------------------------------------------

    public function test_attest_requires_url_or_summary(): void
    {
        $run = Run::factory()->done()->create();
        $r = (new CreateReceiptStub())->execute($run)['receipt'];

        $this->expectException(\InvalidArgumentException::class);
        (new AttestReceipt())->execute(
            $r,
            \App\Models\ApiToken::class,
            'token-id',
            '',
            '',
        );
    }

    public function test_attest_moves_status_to_attested_with_url(): void
    {
        $run = Run::factory()->done()->create();
        $r = (new CreateReceiptStub())->execute($run)['receipt'];

        $result = (new AttestReceipt())->execute(
            $r,
            \App\Models\ApiToken::class,
            'token-id',
            'https://example.com/x',
            null,
        );

        $this->assertTrue($result['transitioned']);
        $r->refresh();
        $this->assertSame(ReceiptStatus::Attested, $r->status);
        $this->assertSame('https://example.com/x', $r->url);
        $this->assertSame(\App\Models\ApiToken::class, $r->attested_by_type);
    }
}
