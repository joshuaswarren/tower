<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AgentStatus;
use App\Enums\EventType;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ingest endpoint acceptance (TWR-011).
 *
 *   - batch of 3 w/1 duplicate dedupe_key => {accepted:2, duplicates:1} and exactly 2 new event rows
 *   - running -> blocked sets run state and agent status
 *   - done -> running => rejected with index, run stays `done`, event row kept with `payload.transition_rejected=true`
 *   - batch of 101 => 422
 *   - event payload > 256KB => 413 with zero rows
 */
class EventsIngestTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgentToken(): array
    {
        $workspace = Workspace::factory()->create();
        $agent = Agent::factory()->inWorkspace($workspace)->working()->create();
        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::factory()->forAgent($agent)
            ->withAbilities([TokenAbility::Ingest])
            ->create([
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);
        return [$token, $plain, $agent];
    }

    private function validEvent(string $type, ?string $to = null, ?string $externalId = null): array
    {
        $event = ['type' => $type, 'occurred_at' => '2026-07-11T14:02:09Z'];
        if ($to !== null || $externalId !== null) {
            $event['run'] = ['external_id' => $externalId ?? 'omp:acme:2026-07-11T13:55'];
            $event['to'] = $to;
        }
        return $event;
    }

    public function test_batch_of_three_with_one_duplicate_returns_correct_counts(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeAgentToken();

        $dupKey = 'dup-key-1';
        $payload = [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::LogNote->value),
                $this->validEvent(EventType::LogNote->value) + ['dedupe_key' => $dupKey],
                $this->validEvent(EventType::LogNote->value) + ['dedupe_key' => $dupKey],
            ],
        ];

        $first = $this->postJson('/api/v1/events', $payload, [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(202);

        $first->assertJson(['accepted' => 2, 'duplicates' => 1, 'rejected' => []]);
        $this->assertSame(2, Event::query()->where('agent_id', $agent->id)->count());
    }

    public function test_running_to_blocked_sets_run_state_and_agent_status(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeAgentToken();

        // First: queued -> running.
        $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::RunStateChanged->value, 'running', 'run-1') + ['from' => 'queued'],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $run = Run::query()->where('agent_id', $agent->id)->where('external_id', 'run-1')->firstOrFail();
        $this->assertSame(RunState::Running, $run->state);

        // Then: running -> blocked.
        $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::RunStateChanged->value, 'blocked', 'run-1') + ['from' => 'running'],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $run->refresh();
        $this->assertSame(RunState::Blocked, $run->state);
        $agent->refresh();
        $this->assertSame(AgentStatus::Blocked, $agent->status);
    }

    public function test_done_to_running_is_rejected_and_keeps_event_with_transition_rejected_flag(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeAgentToken();

        // First: queued -> running -> done.
        $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::RunStateChanged->value, 'running', 'run-illegal') + ['from' => 'queued'],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::RunStateChanged->value, 'done', 'run-illegal') + ['from' => 'running'],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $run = Run::query()->where('agent_id', $agent->id)->where('external_id', 'run-illegal')->firstOrFail();
        $this->assertSame(RunState::Done, $run->state);

        // Now: illegal done -> running.
        $resp = $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::RunStateChanged->value, 'running', 'run-illegal') + ['from' => 'done'],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $resp->assertJsonPath('accepted', 1);
        $resp->assertJsonPath('rejected.0.error', 'illegal transition done->running');

        $run->refresh();
        $this->assertSame(RunState::Done, $run->state, 'run state must stay done after illegal transition');

        $illegalEvent = Event::query()
            ->where('agent_id', $agent->id)
            ->where('to_state', 'running')
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue(
            (bool) ($illegalEvent->payload['transition_rejected'] ?? false),
            'illegal-transition event row must carry payload.transition_rejected=true'
        );
    }

    public function test_batch_of_101_returns_422(): void
    {
        Cache::flush();
        [, $plain] = $this->makeAgentToken();

        $events = [];
        for ($i = 0; $i < 101; $i++) {
            $events[] = $this->validEvent(EventType::LogNote->value) + ['dedupe_key' => "k-{$i}"];
        }

        $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => $events,
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(422);
    }

    public function test_event_payload_over_256kb_returns_413_with_zero_rows(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeAgentToken();

        $big = str_repeat('x', 257 * 1024);
        $resp = $this->postJson('/api/v1/events', [
            'schema' => 'tower.ingest.v1',
            'events' => [
                $this->validEvent(EventType::LogNote->value) + ['payload' => ['blob' => $big]],
            ],
        ], ['Authorization' => 'Bearer '.$plain]);

        $resp->assertStatus(413);
        $this->assertSame(0, Event::query()->where('agent_id', $agent->id)->count(),
            '413 must reject the entire batch with zero rows');
    }
}
