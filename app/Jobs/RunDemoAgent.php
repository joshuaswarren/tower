<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Ingest\ApplyRunTransition;
use App\Actions\Ingest\RecordEvents;
use App\Actions\Receipts\CreateReceiptStub;
use App\Agents\FleetAnalyst;
use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use App\Enums\EventType;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Events\Board\AgentStatusChanged;
use App\Events\Board\DemoOutputStreamed;
use App\Events\Board\ReceiptUpdated;
use App\Events\Board\RunUpdated;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use App\Support\Demo\DemoNarrator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * RunDemoAgent — the demo job (docs/ARCHITECTURE.md §1.7 + §1.4).
 *
 * Flow:
 *  1. Resolve or create a kind=demo agent in the public `demo` workspace,
 *     with an ingest+attest token (so the run dogfoods the full ingest path).
 *  2. Find-or-upsert the demo run (`external_id` derived from
 *     `FleetAnalyst::newExternalId`).
 *  3. Transition queued -> running (synchronous), post the run.state_changed
 *     event through `RecordEvents` so the board sees it.
 *  4. Build the prompt via `FleetAnalyst::buildPrompt`, stream it via the
 *     bound `DemoNarrator`. Each `TextDelta` -> `DemoOutputStreamed` (NOT
 *     queued; inline broadcast for low latency).
 *  5. Transition running -> done, store the report text on the run meta,
 *     create the unattested receipt stub, then attest it with the
 *     assembled report as the artifact (`kind=text`, `summary=report`).
 *     The demo run ends with a real, attested receipt — the honesty
 *     loop demonstrated end to end.
 *
 * The job is on the `demo` queue (ADR-0005) so a flood of demo runs
 * never starves the ingest fan-out queue.
 */
class RunDemoAgent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $externalId,
        public readonly ?string $agentId = null,
    ) {
        $this->onQueue('demo');
    }

    public function handle(
        FleetAnalyst $analyst,
        DemoNarrator $narrator,
        RecordEvents $recorder,
        ApplyRunTransition $transition,
        CreateReceiptStub $stubs,
    ): void {
        $agent = $this->resolveAgent();
        $token = $this->resolveToken($agent);

        $run = $this->upsertRun($agent, $this->externalId);

        $this->emitTransition(
            recorder: $recorder,
            transition: $transition,
            agent: $agent,
            run: $run,
            to: RunState::Running,
        );

        $prompt = $analyst->buildPrompt($agentName = $agent->name);

        $seq = 0;
        $report = $narrator->stream($prompt, function (string $chunk) use ($run, &$seq): void {
            $seq++;
            // Inline (not queued) broadcast — see DemoOutputStreamed.
            try {
                DemoOutputStreamed::dispatch($run->id, $seq, $chunk);
            } catch (Throwable $e) {
                // Broadcasts are best-effort; the run must not fail because
                // Reverb is down. The assembled report is still stored on
                // the run meta + the attested receipt.
                Log::warning('demo chunk broadcast failed', [
                    'run_id' => $run->id,
                    'seq' => $seq,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Persist the report on the run meta so it's retrievable from the
        // run detail view, and so the receipt stub captures a real artifact.
        $run->forceFill(['meta' => array_merge($run->meta ?? [], [
            'demo_report' => $report,
            'demo_chunk_count' => $seq,
            // Anonymous classes stringify with a NUL byte + file path, which
            // Postgres jsonb rejects — keep a clean label only.
            'demo_narrator' => str_contains($narrator::class, "\0") ? 'anonymous' : $narrator::class,
        ])])->save();

        $this->emitTransition(
            recorder: $recorder,
            transition: $transition,
            agent: $agent,
            run: $run,
            to: RunState::Done,
        );

        // Create the receipt stub, then attest it with the report as the
        // artifact (kind=text, summary=report). The demo thus demonstrates
        // a full unattested -> attested cycle in a single run.
        $stubResult = $stubs->execute($run);
        $receipt = $stubResult['receipt'];

        $attested = $this->attestWithReport($receipt, $report, $token);

        // Broadcast the receipt.updated so the board's receipts island
        // reflects the new attestation (NOT queued here either — demo
        // is meant to feel live; the same call pattern is queued on the
        // regular ingest path).
        try {
            ReceiptUpdated::dispatch(
                receiptId: (string) $attested->id,
                runId: (string) $run->id,
                status: $attested->status,
                workspaceId: (string) $agent->workspace_id,
                workspacePublic: $this->workspaceIsPublic($agent),
            );
        } catch (Throwable $e) {
            Log::warning('demo receipt broadcast failed', [
                'receipt_id' => $attested->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find-or-create the demo agent in the public `demo` workspace.
     */
    private function resolveAgent(): Agent
    {
        if ($this->agentId !== null) {
            $existing = Agent::query()->find($this->agentId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $workspace = Workspace::query()
            ->where('name', 'demo')
            ->where('visibility', Workspace::VISIBILITY_PUBLIC)
            ->first();
        if ($workspace === null) {
            $workspace = Workspace::query()->create([
                'id' => (string) Str::ulid(),
                'host_id' => null,
                'name' => 'demo',
                'visibility' => Workspace::VISIBILITY_PUBLIC,
            ]);
        }

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'demo:fleet-analyst')
            ->first();
        if ($agent === null) {
            $agent = Agent::query()->create([
                'id' => (string) Str::ulid(),
                'workspace_id' => $workspace->id,
                'host_id' => null,
                'name' => 'demo:fleet-analyst',
                'kind' => AgentKind::Demo,
                'status' => AgentStatus::Idle,
                'meta' => ['demo' => true],
            ]);
        }

        return $agent;
    }

    /**
     * Find-or-create the demo agent's token. The token holds
     * ingest + attest abilities, mirroring the real producer tokens.
     */
    private function resolveToken(Agent $agent): ApiToken
    {
        $existing = ApiToken::query()
            ->where('owner_type', Agent::class)
            ->where('owner_id', $agent->id)
            ->where('name', 'demo:fleet-analyst')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        // The plaintext is generated but not displayed — the demo only
        // needs the ability-grant, not a usable secret. The CLI
        // `tower:agent:create` prints the plaintext; this internal path
        // does not.
        $plain = 'twr_'.Str::lower(Str::random(40));
        return ApiToken::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'demo:fleet-analyst',
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
            'owner_type' => Agent::class,
            'owner_id' => $agent->id,
            'abilities' => [TokenAbility::Ingest->value, TokenAbility::Attest->value],
        ]);
    }

    private function upsertRun(Agent $agent, string $externalId): Run
    {
        $run = Run::query()
            ->where('agent_id', $agent->id)
            ->where('external_id', $externalId)
            ->first();
        if ($run !== null) {
            return $run;
        }

        return Run::query()->create([
            'id' => (string) Str::ulid(),
            'agent_id' => $agent->id,
            'external_id' => $externalId,
            'title' => 'FleetAnalyst — last 24h briefing',
            'state' => RunState::Queued,
            'meta' => ['demo' => true],
        ]);
    }

    /**
     * Apply a state transition AND record the run.state_changed event
     * through the same path real producers use (`RecordEvents`). That
     * way the demo run is indistinguishable on the board from a real
     * producer run, and the receipt stub is created by the normal
     * `ProcessIngestedBatch` (NOT here — we call `CreateReceiptStub`
     * ourselves below so we can also attest it).
     */
    private function emitTransition(
        RecordEvents $recorder,
        ApplyRunTransition $transition,
        Agent $agent,
        Run $run,
        RunState $to,
    ): void {
        $now = CarbonImmutable::now();
        $result = $transition->execute(
            $run,
            from: $run->state,
            to: $to,
            exitState: $to === RunState::Done ? 'success' : null,
            occurredAt: $now,
        );

        $agentStatus = $transition->mapRunStateToAgentStatus($to);
        $agent->forceFill([
            'status' => $agentStatus,
            'last_event_at' => $now,
        ])->save();

        // Post the canonical event row (matches the ingest envelope's
        // single-event path). RecordEvents is fine with a 1-element batch.
        $envelope = [
            'schema' => 'tower.ingest.v1',
            'sent_at' => $now->toIso8601String(),
            'events' => [[
                'type' => EventType::RunStateChanged->value,
                'run' => [
                    'external_id' => $run->external_id,
                    'title' => $run->title,
                ],
                'from' => $result['from']?->value,
                'to' => $to->value,
                'occurred_at' => $now->toIso8601String(),
                'dedupe_key' => 'demo:'.$run->external_id.':'.$to->value,
                'payload' => ['source' => 'demo'],
            ]],
        ];
        $recorder->execute($agent, $envelope);

        // Inline broadcast of the run + agent status (mirrors what
        // ProcessIngestedBatch would do on the ingest queue, but we
        // want the demo to update the board immediately).
        $workspaceId = (string) $agent->workspace_id;
        $workspacePublic = $this->workspaceIsPublic($agent);
        try {
            RunUpdated::dispatch(
                runId: (string) $run->id,
                agentId: (string) $agent->id,
                state: $to,
                workspaceId: $workspaceId,
                workspacePublic: $workspacePublic,
            );
            AgentStatusChanged::dispatch(
                agentId: (string) $agent->id,
                status: $agentStatus,
                workspaceId: $workspaceId,
                workspacePublic: $workspacePublic,
            );
        } catch (Throwable $e) {
            Log::warning('demo transition broadcast failed', [
                'run_id' => $run->id,
                'to' => $to->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Attest a receipt with the assembled report. Mirrors the contract
     * `App\Http\Controllers\Api\V1\ReceiptController@attest` enforces
     * (token with `attest` ability + non-empty url or summary). The
     * demo report is non-empty, so attestation is legal.
     */
    private function attestWithReport(Receipt $receipt, string $report, ApiToken $token): Receipt
    {
        // Idempotent: re-running the demo shouldn't double-attest.
        if ($receipt->status === ReceiptStatus::Attested) {
            return $receipt;
        }

        $firstLine = trim(Str::limit(preg_replace('/\s+/', ' ', $report), 240, ''));
        $receipt->forceFill([
            'status' => ReceiptStatus::Attested,
            'kind' => 'text',
            'url' => null,
            'summary' => $report,
            'attested_by_type' => ApiToken::class,
            'attested_by_id' => $token->id,
            'attested_at' => CarbonImmutable::now(),
            'stub' => array_merge($receipt->stub ?? [], [
                'attest_first_line' => $firstLine,
            ]),
        ])->save();

        return $receipt;
    }

    private function workspaceIsPublic(Agent $agent): bool
    {
        $row = DB::table('workspaces')
            ->where('id', $agent->workspace_id)
            ->select('visibility')
            ->first();
        return $row !== null && $row->visibility === Workspace::VISIBILITY_PUBLIC;
    }
}
