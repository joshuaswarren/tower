<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AgentStatus;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(): array
    {
        $workspace = Workspace::factory()->create();
        $agent = Agent::factory()->inWorkspace($workspace)->create();
        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::factory()->forAgent($agent)
            ->withAbilities([TokenAbility::Ingest])
            ->create([
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);
        return [$token, $plain, $agent];
    }

    public function test_heartbeat_bumps_last_heartbeat_at(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeToken();
        $before = $agent->last_heartbeat_at;

        $resp = $this->postJson('/api/v1/heartbeat', [], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertOk();

        $agent->refresh();
        $this->assertNotNull($agent->last_heartbeat_at);
        if ($before !== null) {
            $this->assertGreaterThanOrEqual($before, $agent->last_heartbeat_at);
        }
        $resp->assertJson(['ok' => true, 'agent_id' => $agent->id]);
    }

    public function test_heartbeat_with_status_updates_agent_status(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeToken();

        $this->postJson('/api/v1/heartbeat', ['status' => 'blocked'], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertOk();

        $agent->refresh();
        $this->assertSame(AgentStatus::Blocked, $agent->status);
    }
}
