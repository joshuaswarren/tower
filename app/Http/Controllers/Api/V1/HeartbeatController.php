<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHeartbeatRequest;
use App\Models\Agent;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Heartbeat endpoint (ARCHITECTURE.md §1.3).
 *
 * Bumps `last_heartbeat_at` and optionally `status`. The agent is
 * resolved from the token's owner (must be agent-owned).
 */
class HeartbeatController extends Controller
{
    public function store(StoreHeartbeatRequest $request): JsonResponse
    {
        $token = $request->attributes->get('tower_token');
        if (!$token instanceof ApiToken) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $agent = Agent::query()->find($token->owner_id);
        if ($agent === null) {
            return response()->json([
                'error' => 'agent_not_found',
                'message' => 'Token does not point to an existing agent.',
            ], 422);
        }

        $payload = $request->validated();
        $status = $payload['status'] ?? null;

        $updates = [
            'last_heartbeat_at' => now(),
            'last_event_at' => now(),
        ];
        if (is_string($status) && $status !== '') {
            $updates['status'] = AgentStatus::from($status);
        }

        $agent->forceFill($updates)->save();

        return response()->json([
            'ok' => true,
            'agent_id' => $agent->id,
            'last_heartbeat_at' => $agent->last_heartbeat_at?->toIso8601String(),
        ], 200);
    }
}
