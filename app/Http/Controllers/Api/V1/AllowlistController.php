<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAllowlistRequest;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;

/**
 * Allowlist declaration (ARCHITECTURE.md §1.3 + §1.5).
 *
 * Each call is a new monotonically-incremented version. The active
 * manifest is the highest version (model scope `active`). A companion
 * `allowlist.declared` event is written so the feed island can render the
 * timeline.
 */
class AllowlistController extends Controller
{
    public function store(StoreAllowlistRequest $request): JsonResponse
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

        $manifest = $request->validated();

        $latest = Allowlist::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('version')
            ->first();
        $nextVersion = $latest !== null ? ((int) $latest->version) + 1 : 1;

        $allowlist = Allowlist::query()->create([
            'agent_id' => $agent->id,
            'version' => $nextVersion,
            'manifest' => $manifest,
            'declared_at' => now(),
        ]);

        \App\Models\Event::query()->create([
            'agent_id' => $agent->id,
            'run_id' => null,
            'type' => EventType::AllowlistDeclared,
            'from_state' => null,
            'to_state' => null,
            'payload' => [
                'version' => $nextVersion,
                'tools' => $manifest['tools'] ?? [],
                'scopes' => $manifest['scopes'] ?? [],
                'deny' => $manifest['deny'] ?? [],
            ],
            'source' => 'api',
            'dedupe_key' => null,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'version' => $nextVersion,
            'allowlist_id' => $allowlist->id,
        ], 201);
    }
}
