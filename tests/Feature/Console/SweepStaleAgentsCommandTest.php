<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AgentStatus;
use App\Events\Board\AgentStatusChanged;
use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SweepStaleAgentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_travel_181s_flips_agent_offline_and_broadcasts(): void
    {
        Event::fake();
        config()->set('tower.staleness.offline_seconds', 180);

        // Agent heartbeated > 181s ago.
        $agent = Agent::factory()->heartbeatAt(now()->subSeconds(200))->create();
        $fresh = Agent::factory()->heartbeatAt(now()->subSeconds(10))->create();

        $this->artisan('tower:sweep-stale')->assertExitCode(0);

        $agent->refresh();
        $this->assertSame(AgentStatus::Offline, $agent->status);
        $fresh->refresh();
        $this->assertNotSame(AgentStatus::Offline, $fresh->status);

        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) use ($agent) {
            return $e->agentId === $agent->id && $e->status === AgentStatus::Offline;
        });
        Event::assertDispatchedTimes(AgentStatusChanged::class, 1);
    }

    public function test_no_stale_agents_no_broadcasts(): void
    {
        Event::fake();
        Agent::factory()->heartbeatAt(now()->subSeconds(5))->create();
        $this->artisan('tower:sweep-stale')->assertExitCode(0);
        Event::assertNotDispatched(AgentStatusChanged::class);
    }
}
