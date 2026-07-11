<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use App\Enums\EventType;
use App\Enums\HostKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHerdrRequest;
use App\Jobs\ProcessIngestedBatch;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Host;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * herdr bridge ingest (docs/contracts/tower.herdr.v1.json + ARCHITECTURE §1.6).
 *
 * Translation rules:
 *  - Token must be host-owned (the bridge's per-host token); agent-owned
 *    tokens can't POST here.
 *  - `snapshot` batch item: upsert each (host, workspace, pane) and
 *    reconcile — panes ABSENT from the snapshot for a host are flipped to
 *    `offline` (this is how the bridge brings the board back to truth
 *    after a daemon restart).
 *  - `pane.agent_status_changed`: map herdr state 1:1 to AgentStatus and
 *    bump `last_event_at`.
 *  - `pane.agent_detected` / `pane.closed`: light bookkeeping.
 *  - `workspace.created` / `workspace.closed`: light bookkeeping.
 *  - `tails`: ONLY accepted when `tier == 1`. A server-side secret-shaped
 *    pattern screen runs as a backstop and rejects still-secret-looking
 *    tails even when the bridge claims to have redacted them.
 */
class HerdrIngestController extends Controller
{
    public function store(StoreHerdrRequest $request): JsonResponse
    {
        $token = $request->attributes->get('tower_token');
        if (!$token instanceof ApiToken) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Token must own a Host.
        $host = $token->owner_type === Host::class
            ? Host::query()->find($token->owner_id)
            : null;
        if ($host === null) {
            return response()->json([
                'error' => 'host_token_required',
                'message' => 'POST /api/v1/herdr requires a host-owned token.',
            ], 403);
        }

        $envelope = $request->validated();
        $tier = (int) $envelope['tier'];
        $batch = (array) ($envelope['batch'] ?? []);
        $tails = (array) ($envelope['tails'] ?? []);

        $accepted = 0;
        $duplicates = 0;
        $rejected = [];
        $eventIds = [];

        // Track snapshot panes by workspace for the reconciliation step.
        $snapshotPanesByWorkspace = [];

        DB::transaction(function () use (
            $envelope, $host, $batch, $tails, $tier, &$accepted, &$duplicates,
            &$rejected, &$eventIds, &$snapshotPanesByWorkspace,
        ): void {
            $host->forceFill(['last_seen_at' => now()])->save();

            foreach ($batch as $index => $item) {
                $item = (array) $item;
                $kind = (string) ($item['kind'] ?? '');

                try {
                    switch ($kind) {
                        case 'snapshot':
                            $this->processSnapshot($host, $item, $snapshotPanesByWorkspace);
                            $accepted++;
                            break;
                        case 'pane.agent_status_changed':
                            $this->processStatusChanged($host, $item, $eventIds);
                            $accepted++;
                            break;
                        case 'pane.agent_detected':
                            $this->processAgentDetected($host, $item, $eventIds);
                            $accepted++;
                            break;
                        case 'pane.closed':
                            $this->processPaneClosed($host, $item, $eventIds);
                            $accepted++;
                            break;
                        case 'workspace.created':
                            $this->processWorkspaceCreated($host, $item);
                            $accepted++;
                            break;
                        case 'workspace.closed':
                            $this->processWorkspaceClosed($host, $item);
                            $accepted++;
                            break;
                        default:
                            $rejected[] = ['index' => $index, 'error' => "unknown kind {$kind}"];
                    }
                } catch (QueryException $e) {
                    if ($this->isDedupeConflict($e)) {
                        $duplicates++;
                    } else {
                        $rejected[] = ['index' => $index, 'error' => 'db error: '.$e->getMessage()];
                    }
                } catch (\Throwable $e) {
                    $rejected[] = ['index' => $index, 'error' => $e->getMessage()];
                }
            }

            // Tails: tier 0 rejects all tails; tier 1 accepts + screen.
            foreach ($tails as $tIndex => $tail) {
                $tail = (array) $tail;
                if ($tier !== 1) {
                    $rejected[] = [
                        'index' => $tIndex,
                        'error' => "tails require tier=1 (got tier={$tier})",
                    ];
                    continue;
                }
                $content = (string) ($tail['content_redacted'] ?? '');
                if ($this->stillLooksSecret($content)) {
                    $rejected[] = [
                        'index' => $tIndex,
                        'error' => 'tail failed server-side redaction screen',
                    ];
                    continue;
                }
                $pane = (string) ($tail['pane'] ?? '');
                $workspaceName = $this->resolvePaneWorkspace($host, $pane);
                $agent = $this->resolveAgentForPane($host, $workspaceName, $pane);
                if ($agent === null) {
                    $rejected[] = ['index' => $tIndex, 'error' => "pane {$pane} not registered"];
                    continue;
                }
                try {
                    $dedupeKey = is_string($tail['dedupe_key'] ?? null) ? $tail['dedupe_key'] : null;
                    if ($dedupeKey !== null) {
                        $exists = \App\Models\Event::query()
                            ->where('dedupe_key', $dedupeKey)
                            ->exists();
                        if ($exists) {
                            $duplicates++;
                            continue;
                        }
                    }
                    $row = \App\Models\Event::query()->create([
                        'agent_id' => $agent->id,
                        'run_id' => null,
                        'type' => EventType::LogNote,
                        'from_state' => null,
                        'to_state' => null,
                        'payload' => [
                            'tail' => $content,
                            'redactions_applied' => (int) ($tail['redactions_applied'] ?? 0),
                            'pane' => $pane,
                            'workspace' => $workspaceName,
                        ],
                        'source' => 'herdr-bridge',
                        'dedupe_key' => $dedupeKey,
                        'occurred_at' => $this->occurredAt($tail['at'] ?? null),
                        'received_at' => now(),
                    ]);
                    $eventIds[] = $row->id;
                    $accepted++;
                } catch (QueryException $e) {
                    if ($this->isDedupeConflict($e)) {
                        $duplicates++;
                    } else {
                        $rejected[] = ['index' => $tIndex, 'error' => 'db error: '.$e->getMessage()];
                    }
                }
            }
            // Snapshot reconciliation runs ONLY when the batch carried at
            // least one `snapshot` item — otherwise we'd incorrectly flip
            // every pane-agent on this host to `offline` for every status
            // change. The whole point of reconciliation is "what the bridge
            // last saw on the wire" vs. what we have.
            if (!empty($snapshotPanesByWorkspace)) {
                $this->reconcileSnapshot($host, $snapshotPanesByWorkspace);
            }
        });

        if (!empty($eventIds)) {
            ProcessIngestedBatch::dispatch($eventIds)->onQueue('ingest');
        }

        return response()->json([
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
        ], 202);
    }

    private function processSnapshot(Host $host, array $item, array &$snapshotPanesByWorkspace): void
    {
        $workspaces = (array) ($item['workspaces'] ?? []);
        foreach ($workspaces as $wsRef) {
            $wsRef = (array) $wsRef;
            $workspace = $this->resolveWorkspace($host, (string) ($wsRef['name'] ?? ''));
            $panes = (array) ($wsRef['panes'] ?? []);
            $snapshotPanesByWorkspace[(string) $workspace->id] = [];
            foreach ($panes as $paneRef) {
                $paneRef = (array) $paneRef;
                $pane = (string) ($paneRef['pane'] ?? '');
                $agentKindStr = (string) ($paneRef['agent_kind'] ?? 'claude-code');
                $state = (string) ($paneRef['state'] ?? 'idle');
                $agent = $this->upsertPaneAgent($host, $workspace, $pane, $agentKindStr, $state);
                $snapshotPanesByWorkspace[(string) $workspace->id][] = (string) $agent->id;
            }
        }
    }

    private function processStatusChanged(Host $host, array $item, array &$eventIds): void
    {
        $workspaceName = (string) ($item['workspace'] ?? '');
        $pane = (string) ($item['pane'] ?? '');
        $agentKindStr = (string) ($item['agent_kind'] ?? 'claude-code');
        $to = (string) ($item['to'] ?? '');
        $from = (string) ($item['from'] ?? '');
        $dedupeKey = is_string($item['dedupe_key'] ?? null) ? $item['dedupe_key'] : null;

        $workspace = $this->resolveWorkspace($host, $workspaceName);
        $agent = $this->upsertPaneAgent($host, $workspace, $pane, $agentKindStr, $to);

        // Status change event row.
        $row = \App\Models\Event::query()->create([
            'agent_id' => $agent->id,
            'run_id' => null,
            'type' => EventType::RunStateChanged,
            'from_state' => $this->mapHerdrState($from) ?: null,
            'to_state' => $this->mapHerdrState($to) ?: null,
            'payload' => [
                'herdr' => [
                    'workspace' => $workspaceName,
                    'pane' => $pane,
                    'from' => $from,
                    'to' => $to,
                ],
            ],
            'source' => 'herdr-bridge',
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $this->occurredAt($item['at'] ?? null),
            'received_at' => now(),
        ]);
        $eventIds[] = $row->id;
    }

    private function processAgentDetected(Host $host, array $item, array &$eventIds): void
    {
        $workspaceName = (string) ($item['workspace'] ?? '');
        $pane = (string) ($item['pane'] ?? '');
        $agentKindStr = (string) ($item['agent_kind'] ?? 'claude-code');
        $dedupeKey = is_string($item['dedupe_key'] ?? null) ? $item['dedupe_key'] : null;

        $workspace = $this->resolveWorkspace($host, $workspaceName);
        $agent = $this->upsertPaneAgent($host, $workspace, $pane, $agentKindStr, 'working');

        $row = \App\Models\Event::query()->create([
            'agent_id' => $agent->id,
            'run_id' => null,
            'type' => EventType::AgentHeartbeat,
            'from_state' => null,
            'to_state' => null,
            'payload' => [
                'herdr' => [
                    'workspace' => $workspaceName,
                    'pane' => $pane,
                    'detected' => true,
                ],
            ],
            'source' => 'herdr-bridge',
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $this->occurredAt($item['at'] ?? null),
            'received_at' => now(),
        ]);
        $eventIds[] = $row->id;
    }

    private function processPaneClosed(Host $host, array $item, array &$eventIds): void
    {
        $workspaceName = (string) ($item['workspace'] ?? '');
        $pane = (string) ($item['pane'] ?? '');
        $dedupeKey = is_string($item['dedupe_key'] ?? null) ? $item['dedupe_key'] : null;

        $workspace = $this->resolveWorkspace($host, $workspaceName);
        $agent = $this->resolveAgentForPane($host, $workspaceName, $pane);

        if ($agent !== null) {
            $agent->forceFill(['status' => AgentStatus::Offline])->save();
            $row = \App\Models\Event::query()->create([
                'agent_id' => $agent->id,
                'run_id' => null,
                'type' => EventType::AgentHeartbeat,
                'from_state' => null,
                'to_state' => null,
                'payload' => [
                    'herdr' => [
                        'workspace' => $workspaceName,
                        'pane' => $pane,
                        'closed' => true,
                    ],
                ],
                'source' => 'herdr-bridge',
                'dedupe_key' => $dedupeKey,
                'occurred_at' => $this->occurredAt($item['at'] ?? null),
                'received_at' => now(),
            ]);
            $eventIds[] = $row->id;
        }
    }

    private function processWorkspaceCreated(Host $host, array $item): void
    {
        $name = (string) ($item['workspace'] ?? '');
        if ($name === '') {
            return;
        }
        $this->resolveWorkspace($host, $name);
    }

    private function processWorkspaceClosed(Host $host, array $item): void
    {
        $name = (string) ($item['workspace'] ?? '');
        if ($name === '') {
            return;
        }
        Workspace::query()
            ->where('host_id', $host->id)
            ->where('name', $name)
            ->delete();
    }

    /**
     * Bring agents belonging to this host back to truth: panes absent from
     * the snapshot are marked `offline` so the board never lies green.
     */
    private function reconcileSnapshot(Host $host, array $snapshotPanesByWorkspace): void
    {
        $presentAgentIds = [];
        foreach ($snapshotPanesByWorkspace as $agentIds) {
            foreach ((array) $agentIds as $id) {
                $presentAgentIds[] = (string) $id;
            }
        }

        // Only flag pane-agents owned by this host that were not in the
        // snapshot. Real (non-pane) agents are not touched.
        Agent::query()
            ->where('host_id', $host->id)
            ->whereNotIn('id', $presentAgentIds ?: ['__none__'])
            ->where('status', '!=', AgentStatus::Offline->value)
            ->update([
                'status' => AgentStatus::Offline->value,
                'last_event_at' => now(),
            ]);
    }

    private function resolveWorkspace(Host $host, string $name): Workspace
    {
        $existing = Workspace::query()
            ->where('host_id', $host->id)
            ->where('name', $name)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return Workspace::query()->create([
            'id' => (string) Str::ulid(),
            'host_id' => $host->id,
            'name' => $name,
            'visibility' => Workspace::VISIBILITY_PRIVATE,
        ]);
    }

    private function resolvePaneWorkspace(Host $host, string $pane): string
    {
        $agent = Agent::query()
            ->where('host_id', $host->id)
            ->where('name', 'like', "herdr:{$host->name}:{$pane}")
            ->first();
        if ($agent !== null) {
            $workspace = $agent->workspace()->first();
            if ($workspace !== null) {
                return $workspace->name;
            }
        }
        return 'default';
    }

    private function resolveAgentForPane(Host $host, string $workspaceName, string $pane): ?Agent
    {
        return Agent::query()
            ->where('host_id', $host->id)
            ->where('name', "herdr:{$host->name}:{$pane}")
            ->first();
    }

    private function upsertPaneAgent(
        Host $host,
        Workspace $workspace,
        string $pane,
        string $agentKindStr,
        string $herdrState,
    ): Agent {
        $name = "herdr:{$host->name}:{$pane}";
        $agentKind = $this->mapAgentKind($agentKindStr);
        $status = $this->mapHerdrState($herdrState) ?? AgentStatus::Idle;

        $existing = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->first();
        if ($existing !== null) {
            $existing->forceFill([
                'host_id' => $host->id,
                'status' => $status,
                'last_heartbeat_at' => now(),
                'last_event_at' => now(),
            ])->save();
            return $existing;
        }

        return Agent::query()->create([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspace->id,
            'host_id' => $host->id,
            'name' => $name,
            'kind' => $agentKind,
            'status' => $status,
            'last_heartbeat_at' => now(),
            'last_event_at' => now(),
            'meta' => ['pane' => $pane, 'host' => $host->name],
        ]);
    }

    private function mapHerdrState(string $state): ?AgentStatus
    {
        return match ($state) {
            'idle' => AgentStatus::Idle,
            'working' => AgentStatus::Working,
            'blocked' => AgentStatus::Blocked,
            'done' => AgentStatus::Done,
            default => null,
        };
    }

    private function mapAgentKind(string $raw): AgentKind
    {
        $normalized = strtolower(trim($raw));
        return match ($normalized) {
            'claude-code', 'claude_code', 'claudecode' => AgentKind::ClaudeCode,
            'codex' => AgentKind::Codex,
            'omp' => AgentKind::Omp,
            'openclaw' => AgentKind::Openclaw,
            'herdr' => AgentKind::Herdr,
            'ci' => AgentKind::Ci,
            'demo' => AgentKind::Demo,
            default => AgentKind::Other,
        };
    }

    /**
     * Server-side secret-shaped-pattern backstop. The bridge is supposed
     * to have redacted locally, but Tower never trusts a producer. Any
     * tail that still looks like a credential is rejected.
     */
    private function stillLooksSecret(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        $patterns = [
            '/\bAKIA[0-9A-Z]{16}\b/',                  // AWS access key id
            '/\bASIA[0-9A-Z]{16}\b/',                  // AWS session key
            '/\bsk-[A-Za-z0-9]{20,}\b/',               // OpenAI / sk-… style
            '/\bghp_[A-Za-z0-9]{20,}\b/',              // GitHub personal token
            '/\bglpat-[A-Za-z0-9_\-]{20,}\b/',         // GitLab PAT
            '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',      // Slack tokens
            '/\b-----BEGIN [A-Z ]*PRIVATE KEY-----/', // PEM
            '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{5,}\b/', // JWT
            '/\bAIza[0-9A-Za-z_\-]{35}\b/',            // Google API key
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }
        return false;
    }

    private function occurredAt(mixed $raw): Carbon
    {
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                // fall through
            }
        }
        return Carbon::now();
    }

    private function isDedupeConflict(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
