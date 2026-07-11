<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EventType;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AllowlistIngestTest extends TestCase
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

    public function test_allowlist_creates_versioned_row_and_event(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeToken();

        $resp = $this->postJson('/api/v1/allowlist', [
            'tools' => ['bash', 'edit'],
            'scopes' => ['repo:tower'],
            'deny' => ['prod:*'],
        ], ['Authorization' => 'Bearer '.$plain])->assertCreated();

        $resp->assertJsonPath('version', 1);
        $this->assertSame(1, Allowlist::query()->where('agent_id', $agent->id)->count());

        // Companion event
        $event = Event::query()
            ->where('agent_id', $agent->id)
            ->where('type', EventType::AllowlistDeclared)
            ->firstOrFail();
        $this->assertSame(1, $event->payload['version']);
        $this->assertSame(['bash', 'edit'], $event->payload['tools']);
    }

    public function test_second_allowlist_increments_version(): void
    {
        Cache::flush();
        [, $plain, $agent] = $this->makeToken();

        $this->postJson('/api/v1/allowlist', ['tools' => ['bash']], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertCreated();

        $resp = $this->postJson('/api/v1/allowlist', ['tools' => ['bash', 'edit']], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertCreated();

        $resp->assertJsonPath('version', 2);

        $active = Allowlist::active()->where('agent_id', $agent->id)->firstOrFail();
        $this->assertSame(2, $active->version);
    }
}
