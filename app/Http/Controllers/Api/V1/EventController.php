<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ingest\RecordEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventsRequest;
use App\Jobs\ProcessIngestedBatch;
use App\Models\Agent;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic ingest endpoint (docs/contracts/tower.ingest.v1.json).
 *
 * The synchronous path is dumb and fast:
 *   validate envelope -> 422 (envelope shape), 413 (oversize),
 *   RecordEvents (TX insert + dedupe + transitions + agent denorm) -> 202,
 *   ProcessIngestedBatch::dispatch([event ids]) on the `ingest` queue.
 *
 * The producer MUST never lose 99 good events because one was malformed.
 * Per-event outcomes are returned in `accepted` / `duplicates` / `rejected`.
 */
class EventController extends Controller
{
    public function __construct(
        private readonly RecordEvents $recordEvents,
    ) {
    }

    public function store(StoreEventsRequest $request): JsonResponse
    {
        $token = $this->requireToken($request);
        if ($token === null) {
            return $this->unauthenticated();
        }

        $agent = $this->resolveAgent($token);
        if ($agent === null) {
            return response()->json([
                'error' => 'agent_required',
                'message' => 'Token must be agent-owned to ingest generic events.',
            ], 422);
        }

        // Per-event payload cap: a single event whose encoded JSON exceeds
        // `tower.ingest.max_payload_kb` must be rejected with 413 and ZERO
        // rows written. We check the raw body length first because
        // Laravel's validator operates on parsed arrays.
        $maxBytes = ((int) config('tower.ingest.max_payload_kb', 256)) * 1024;
        $contentLength = (int) $request->server('CONTENT_LENGTH', strlen($request->getContent()));
        $raw = $request->getContent();
        $actualSize = max($contentLength, strlen($raw));
        if ($actualSize > $maxBytes) {
            return response()->json([
                'error' => 'payload_too_large',
                'message' => "Request body {$actualSize}B exceeds max {$maxBytes}B.",
                'max_bytes' => $maxBytes,
            ], 413);
        }

        // Also reject if any individual event payload json-encodes above the
        // cap. We surface the first offender and abort the whole batch with
        // 413, per spec.
        $events = (array) $request->input('events', []);
        foreach ($events as $i => $event) {
            $encoded = strlen(json_encode($event, JSON_UNESCAPED_UNICODE));
            if ($encoded > $maxBytes) {
                return response()->json([
                    'error' => 'payload_too_large',
                    'message' => "Event {$i} payload {$encoded}B exceeds max {$maxBytes}B.",
                    'index' => $i,
                    'max_bytes' => $maxBytes,
                ], 413);
            }
        }

        $result = $this->recordEvents->execute($agent, $request->validated());

        if (!empty($result['event_ids'])) {
            ProcessIngestedBatch::dispatch($result['event_ids'])->onQueue('ingest');
        }

        return response()->json([
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
            'rejected' => $result['rejected'],
        ], 202);
    }

    private function requireToken(Request $request): ?ApiToken
    {
        $token = $request->attributes->get('tower_token');
        return $token instanceof ApiToken ? $token : null;
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => 'unauthenticated',
            'message' => 'Bearer token required.',
        ], 401);
    }

    /**
     * For agent-owned tokens, the agent IS the principal — easy. For
     * host-owned bridge tokens the agent is the pane-agent referenced in
     * the envelope; for the generic ingest endpoint we require the token
     * to be agent-owned.
     */
    private function resolveAgent(ApiToken $token): ?Agent
    {
        if ($token->owner_type === Agent::class) {
            return Agent::query()->find($token->owner_id);
        }
        return null;
    }
}
