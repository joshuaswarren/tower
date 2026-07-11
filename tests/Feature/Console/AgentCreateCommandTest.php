<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AgentKind;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_create_prints_plaintext_token_exactly_once(): void
    {
        $this->artisan('tower:agent:create', [
            'name' => 'omp-lane-acme',
            '--workspace' => 'acme',
            '--kind' => 'omp',
            '--abilities' => 'ingest,attest',
        ])->assertExitCode(0);

        // Agent + workspace were created.
        $workspace = Workspace::query()->where('name', 'acme')->firstOrFail();
        $agent = Agent::query()->where('name', 'omp-lane-acme')
            ->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(AgentKind::Omp, $agent->kind);

        // Token row exists with a hash, never a plaintext copy.
        $token = ApiToken::query()
            ->where('owner_type', Agent::class)
            ->where('owner_id', $agent->id)
            ->firstOrFail();
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertSame(12, strlen($token->token_prefix));
        $this->assertSame(['ingest', 'attest'], $token->abilities);
        $this->assertSame(TokenAbility::Ingest->value, $token->abilities[0]);

        // The token_hash must be a valid SHA-256 hex string.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->token_hash);
    }

    public function test_agent_create_refuses_unknown_ability(): void
    {
        $this->artisan('tower:agent:create', [
            'name' => 'bogus',
            '--workspace' => 'w',
            '--abilities' => 'ingest,nonsense',
        ])->expectsOutputToContain('Unknown ability')
          ->assertExitCode(0);

        $token = ApiToken::query()->firstOrFail();
        $this->assertSame(['ingest'], $token->abilities);
    }
}
