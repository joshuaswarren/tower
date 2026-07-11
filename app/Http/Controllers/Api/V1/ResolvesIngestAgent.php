<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Host;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Shared helpers for the v1 ingest controllers. Resolves the API token's
 * owner (which is always an Agent for per-agent tokens) and lazily creates
 * the Workspace/Host scaffold on first ingest from a new agent.
 */
trait ResolvesIngestAgent
{
    private function resolveAgent(Request $request, ?string $explicitAgentId = null): Agent
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('tower_token');

        // herdr's bridge token is owned by a Host, not an Agent — the
        // batch carries the per-pane agent identity.
        if ($token->owner_type === Host::class) {
            if ($explicitAgentId === null || $explicitAgentId === '') {
                abort(response()->json([
                    'error' => 'validation',
                    'message' => 'herdr envelopes must identify the target agent.',
                ], 422));
            }

            $agent = Agent::query()->find($explicitAgentId);
            if ($agent !== null) {
                return $agent;
            }
        }

        if ($explicitAgentId !== null && $explicitAgentId !== '') {
            $agent = Agent::query()->find($explicitAgentId);
            if ($agent !== null) {
                return $agent;
            }
        }

        // Token owner is an Agent — the canonical ingest path.
        if ($token->owner_type === Agent::class && $token->owner_id !== null) {
            $agent = Agent::query()->find($token->owner_id);
            if ($agent !== null) {
                return $agent;
            }
        }

        // Cold start: create a default agent attached to a default workspace
        // so the first ingest from a brand-new token doesn't 404.
        $workspace = Workspace::query()->firstOrCreate(
            ['host_id' => null, 'name' => 'default'],
            ['visibility' => Workspace::VISIBILITY_PRIVATE],
        );

        return Agent::query()->create([
            'workspace_id' => $workspace->id,
            'host_id' => null,
            'name' => $token->name,
            'kind' => AgentKind::Other,
            'status' => AgentStatus::Idle,
            'last_heartbeat_at' => null,
            'last_event_at' => null,
            'meta' => ['token_id' => $token->id],
        ]);
    }
}
