<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Drift\DetectDrift;
use App\Actions\Receipts\CreateReceiptStub;
use App\Enums\AgentStatus;
use App\Enums\DriftSeverity;
use App\Enums\RunState;
use App\Events\Board\AgentStatusChanged;
use App\Events\Board\DriftFlagRaised;
use App\Events\Board\ReceiptUpdated;
use App\Events\Board\RunUpdated;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Post-ingest fan-out (ARCHITECTURE.md §1.4).
 *
 * After the synchronous ingest path returns 202, this job does the work
 * that can lag without losing producer batches:
 *  - DetectDrift on the freshly-inserted event ids
 *  - CreateReceiptStub for every `done` transition we just wrote
 *  - Broadcast fan-out: AgentStatusChanged, RunUpdated, DriftFlagRaised,
 *    ReceiptUpdated — all on the `ingest` queue per ADR-0005.
 *
 * The job receives a list of event ids (not the events themselves) so the
 * payload stays small even for big batches.
 */
class ProcessIngestedBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<int>  $eventIds
     */
    public function __construct(public array $eventIds)
    {
    }

    public function handle(DetectDrift $drift, CreateReceiptStub $stubs): void
    {
        if (empty($this->eventIds)) {
            return;
        }

        $events = Event::query()
            ->whereIn('id', $this->eventIds)
            ->get();

        // 1) Drift detection on the tool invocations carried by these events.
        $newFlags = $drift->execute($this->eventIds);
        foreach ($newFlags as $flag) {
            $this->safeBroadcast(new DriftFlagRaised(
                driftFlagId: (string) $flag->id,
                agentId: (string) $flag->agent_id,
                severity: $flag->severity ?? DriftSeverity::Warn,
                workspaceId: $this->resolveWorkspaceId((string) $flag->agent_id),
                workspacePublic: $this->resolveWorkspacePublic((string) $flag->agent_id),
            ));
        }

        // 2) For every `done` event in this batch, ensure the receipt stub.
        $doneEvents = $events->where('type', \App\Enums\EventType::RunStateChanged)
            ->filter(fn (Event $e) => $e->to_state === RunState::Done->value);

        $runIdsToBroadcast = [];
        $agentIdsToBroadcast = [];

        foreach ($doneEvents as $event) {
            if ($event->run_id === null) {
                continue;
            }
            $result = $stubs->execute($event->run()->firstOrFail());
            $receipt = $result['receipt'];

            $agentId = (string) $event->agent_id;
            $workspaceId = $this->resolveWorkspaceId($agentId);
            $workspacePublic = $this->resolveWorkspacePublic($agentId);

            $this->safeBroadcast(new ReceiptUpdated(
                receiptId: (string) $receipt->id,
                runId: (string) $event->run_id,
                status: $receipt->status,
                workspaceId: $workspaceId,
                workspacePublic: $workspacePublic,
            ));

            $runIdsToBroadcast[] = (string) $event->run_id;
            $agentIdsToBroadcast[] = $agentId;
        }

        // 3) Broadcast RunUpdated + AgentStatusChanged for runs whose state
        //    changed in this batch. We re-read each run to get the canonical
        //    post-transition state and a fresh `workspaceId` for the payload.
        $runIds = $events->pluck('run_id')
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();

        $agentIds = $events->pluck('agent_id')
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();

        foreach ($runIds as $runId) {
            $run = \App\Models\Run::query()->with('agent')->find($runId);
            if ($run === null) {
                continue;
            }
            $agentId = (string) $run->agent_id;
            $this->safeBroadcast(new RunUpdated(
                runId: (string) $run->id,
                agentId: $agentId,
                state: $run->state,
                workspaceId: $this->resolveWorkspaceId($agentId),
                workspacePublic: $this->resolveWorkspacePublic($agentId),
            ));
        }

        // 4) Agent status broadcast. We re-read each agent to capture the
        //    denormalized status that ApplyRunTransition wrote. The board
        //    uses this to flip the agent chip.
        foreach ($agentIds as $agentId) {
            $agent = \App\Models\Agent::query()->find($agentId);
            if ($agent === null) {
                continue;
            }
            $this->safeBroadcast(new AgentStatusChanged(
                agentId: (string) $agent->id,
                status: $agent->status ?? AgentStatus::Idle,
                workspaceId: $this->resolveWorkspaceId((string) $agent->id),
                workspacePublic: $this->resolveWorkspacePublic((string) $agent->id),
            ));
        }
    }

    private function resolveWorkspaceId(string $agentId): string
    {
        $row = DB::table('agents')
            ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
            ->where('agents.id', $agentId)
            ->select('workspaces.id as workspace_id', 'workspaces.visibility as visibility')
            ->first();

        return $row !== null ? (string) $row->workspace_id : '';
    }

    private function resolveWorkspacePublic(string $agentId): bool
    {
        $row = DB::table('agents')
            ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
            ->where('agents.id', $agentId)
            ->select('workspaces.visibility as visibility')
            ->first();

        return $row !== null && $row->visibility === \App\Models\Workspace::VISIBILITY_PUBLIC;
    }

    private function safeBroadcast(object $event): void
    {
        try {
            $event::dispatch($event);
        } catch (\Throwable $e) {
            // Broadcasts are best-effort; the synchronous write is the
            // source of truth (ADR-0004). The job should not fail because
            // the queue connection is flaky.
            report($e);
        }
    }
}
