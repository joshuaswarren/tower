<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Receipts\AttestReceipt;
use App\Enums\EventType;
use App\Enums\ReceiptStatus;
use App\Events\Board\ReceiptUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReceiptAttestRequest;
use App\Models\ApiToken;
use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receipt attestation (ARCHITECTURE.md §1.2 honesty model).
 *
 *   - Token must carry the `attest` ability (gated by `ability:attest`).
 *   - Attestation is immutable: a second call on an already-attested
 *     receipt returns 409.
 *   - The attestor principal is the ApiToken (machine path) — the admin
 *     Livewire UI uses the User session for the same flow.
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly AttestReceipt $attest,
    ) {
    }

    public function attestAction(StoreReceiptAttestRequest $request, string $receiptId): JsonResponse
    {
        $token = $request->attributes->get('tower_token');
        if (!$token instanceof ApiToken) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $receipt = Receipt::query()->find($receiptId);
        if ($receipt === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Receipt {$receiptId} not found.",
            ], 404);
        }

        if ($receipt->status === ReceiptStatus::Attested) {
            return response()->json([
                'error' => 'already_attested',
                'message' => 'Receipt is already attested; attestation is immutable.',
                'attested_at' => $receipt->attested_at?->toIso8601String(),
            ], 409);
        }

        $payload = $request->validated();
        $url = $payload['url'] ?? null;
        $summary = $payload['summary'] ?? null;
        $kind = $payload['kind'] ?? null;

        // Empty + empty is rejected here (the form request only validates
        // shape; the contract is at-least-one).
        $hasUrl = is_string($url) && trim($url) !== '';
        $hasSummary = is_string($summary) && trim($summary) !== '';
        if (!$hasUrl && !$hasSummary) {
            return response()->json([
                'error' => 'attestation_payload_required',
                'message' => 'Attestation requires a non-empty url or summary.',
            ], 422);
        }

        try {
            $result = $this->attest->execute(
                $receipt,
                ApiToken::class,
                (string) $token->id,
                $hasUrl ? $url : null,
                $hasSummary ? $summary : null,
                is_string($kind) ? $kind : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'attestation_payload_required',
                'message' => $e->getMessage(),
            ], 422);
        }

        // Companion event for the feed island.
        \App\Models\Event::query()->create([
            'agent_id' => $receipt->agent_id,
            'run_id' => $receipt->run_id,
            'type' => EventType::ReceiptAttested,
            'from_state' => null,
            'to_state' => null,
            'payload' => [
                'receipt_id' => $receipt->id,
                'url' => $receipt->url,
                'summary' => $receipt->summary,
            ],
            'source' => 'api',
            'dedupe_key' => null,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $workspacePublic = $this->resolveWorkspacePublic((string) $receipt->agent_id);
        $workspaceId = $this->resolveWorkspaceId((string) $receipt->agent_id);

        try {
            ReceiptUpdated::dispatch(
                (string) $receipt->id,
                (string) $receipt->run_id,
                $receipt->status,
                $workspaceId,
                $workspacePublic,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'ok' => true,
            'receipt_id' => $receipt->id,
            'status' => $receipt->status->value,
            'attested_at' => $receipt->attested_at?->toIso8601String(),
        ], 200);
    }

    private function resolveWorkspaceId(string $agentId): string
    {
        $row = \Illuminate\Support\Facades\DB::table('agents')
            ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
            ->where('agents.id', $agentId)
            ->value('workspaces.id');
        return $row !== null ? (string) $row : '';
    }

    private function resolveWorkspacePublic(string $agentId): bool
    {
        $row = \Illuminate\Support\Facades\DB::table('agents')
            ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
            ->where('agents.id', $agentId)
            ->value('workspaces.visibility');
        return $row === \App\Models\Workspace::VISIBILITY_PUBLIC;
    }
}
