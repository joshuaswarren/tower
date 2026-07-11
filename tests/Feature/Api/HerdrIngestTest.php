<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AgentStatus;
use App\Enums\HostKind;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class HerdrIngestTest extends TestCase
{
    use RefreshDatabase;

    private function hostToken(): array
    {
        $host = Host::factory()->herdr()->create(['name' => 'claude-a']);
        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::factory()->forHost($host)
            ->withAbilities([TokenAbility::IngestHerdr])
            ->create([
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);
        return [$token, $plain, $host];
    }

    public function test_envelope_creates_host_workspace_and_pane_agents(): void
    {
        Cache::flush();
        [, $plain, $host] = $this->hostToken();

        $envelope = [
            'schema' => 'tower.herdr.v1',
            'bridge_version' => '0.1.0',
            'herdr_version' => '0.4.2',
            'host' => 'claude-a',
            'tier' => 0,
            'sent_at' => '2026-07-12T09:15:04Z',
            'batch' => [
                [
                    'kind' => 'snapshot',
                    'workspaces' => [
                        [
                            'name' => 'tower',
                            'panes' => [
                                ['pane' => '%3', 'agent_kind' => 'claude-code', 'state' => 'working'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v1/herdr', $envelope, [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(202);

        $agent = Agent::query()->where('host_id', $host->id)->where('name', 'herdr:claude-a:%3')->firstOrFail();
        $this->assertSame(AgentStatus::Working, $agent->status);
        $this->assertSame('tower', $agent->workspace->name);
    }

    public function test_blocked_pane_translates_to_blocked_agent_status(): void
    {
        Cache::flush();
        [, $plain, $host] = $this->hostToken();

        $envelope = [
            'schema' => 'tower.herdr.v1',
            'host' => 'claude-a',
            'tier' => 0,
            'batch' => [
                [
                    'kind' => 'pane.agent_status_changed',
                    'workspace' => 'tower',
                    'pane' => '%3',
                    'agent_kind' => 'claude-code',
                    'from' => 'working',
                    'to' => 'blocked',
                    'at' => '2026-07-12T09:15:02Z',
                    'dedupe_key' => 'claude-a:%3:8842',
                ],
            ],
        ];

        $this->postJson('/api/v1/herdr', $envelope, [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(202);

        $agent = Agent::query()->where('host_id', $host->id)->where('name', 'herdr:claude-a:%3')->firstOrFail();
        $this->assertSame(AgentStatus::Blocked, $agent->status);
    }

    public function test_snapshot_reconciles_missing_pane_to_offline(): void
    {
        Cache::flush();
        [, $plain, $host] = $this->hostToken();

        // First batch: register two panes
        $this->postJson('/api/v1/herdr', [
            'schema' => 'tower.herdr.v1',
            'host' => 'claude-a',
            'tier' => 0,
            'batch' => [
                [
                    'kind' => 'pane.agent_detected',
                    'workspace' => 'tower',
                    'pane' => '%3',
                    'agent_kind' => 'claude-code',
                    'at' => '2026-07-12T09:15:01Z',
                    'dedupe_key' => 'claude-a:%3:detected',
                ],
                [
                    'kind' => 'pane.agent_detected',
                    'workspace' => 'tower',
                    'pane' => '%5',
                    'agent_kind' => 'codex',
                    'at' => '2026-07-12T09:15:02Z',
                    'dedupe_key' => 'claude-a:%5:detected',
                ],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        // Now: snapshot only mentions %3 — %5 should go offline.
        $this->postJson('/api/v1/herdr', [
            'schema' => 'tower.herdr.v1',
            'host' => 'claude-a',
            'tier' => 0,
            'batch' => [
                [
                    'kind' => 'snapshot',
                    'workspaces' => [
                        [
                            'name' => 'tower',
                            'panes' => [
                                ['pane' => '%3', 'agent_kind' => 'claude-code', 'state' => 'working'],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(202);

        $present = Agent::query()->where('name', 'herdr:claude-a:%3')->firstOrFail();
        $absent = Agent::query()->where('name', 'herdr:claude-a:%5')->firstOrFail();
        $this->assertNotSame(AgentStatus::Offline, $present->status);
        $this->assertSame(AgentStatus::Offline, $absent->status);
    }

    public function test_tier_zero_tail_is_rejected(): void
    {
        Cache::flush();
        [, $plain] = $this->hostToken();

        $envelope = [
            'schema' => 'tower.herdr.v1',
            'host' => 'claude-a',
            'tier' => 0,
            'batch' => [],
            'tails' => [
                [
                    'pane' => '%3',
                    'dedupe_key' => 'claude-a:%3:tail',
                    'content_redacted' => 'no secrets here',
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/herdr', $envelope, [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(202);

        $resp->assertJsonPath('accepted', 0);
        $resp->assertJsonPath('rejected.0.error', 'tails require tier=1 (got tier=0)');
    }

    public function test_tier_one_secret_shaped_tail_is_rejected_by_server_side_screen(): void
    {
        Cache::flush();
        [, $plain] = $this->hostToken();

        $envelope = [
            'schema' => 'tower.herdr.v1',
            'host' => 'claude-a',
            'tier' => 1,
            'batch' => [
                [
                    'kind' => 'pane.agent_detected',
                    'workspace' => 'tower',
                    'pane' => '%3',
                    'agent_kind' => 'claude-code',
                    'at' => '2026-07-12T09:15:01Z',
                    'dedupe_key' => 'claude-a:%3:detected',
                ],
            ],
            'tails' => [
                [
                    'pane' => '%3',
                    'dedupe_key' => 'claude-a:%3:tail-secret',
                    'content_redacted' => 'export AWS_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE secret',
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/herdr', $envelope, [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(202);

        $tailRejected = collect($resp->json('rejected'))->firstWhere('error', 'tail failed server-side redaction screen');
        $this->assertNotNull($tailRejected, 'server-side screen must reject a still-secret tail');
    }

    public function test_agent_owned_token_cannot_post_herdr(): void
    {
        Cache::flush();
        $workspace = \App\Models\Workspace::factory()->create();
        $agent = Agent::factory()->inWorkspace($workspace)->create();
        $plain = 'twr_'.Str::lower(Str::random(40));
        ApiToken::factory()->forAgent($agent)
            ->withAbilities([TokenAbility::IngestHerdr])
            ->create([
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);

        $this->postJson('/api/v1/herdr', [
            'schema' => 'tower.herdr.v1',
            'host' => 'whatever',
            'tier' => 0,
            'batch' => [],
        ], ['Authorization' => 'Bearer '.$plain])->assertStatus(403);
    }
}
