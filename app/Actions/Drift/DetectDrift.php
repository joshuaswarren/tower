<?php

declare(strict_types=1);

namespace App\Actions\Drift;

use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use App\Enums\EventType;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\DriftFlag;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Drift detection (ARCHITECTURE.md §1.5).
 *
 * Inputs: a batch of freshly-inserted event ids (the `tool.invoked` events
 * plus any `run.state_changed` events that carry `payload.tools_used[]`).
 *
 * Behavior:
 *  - Global kill switch: `config('tower.drift.enabled')` must be true.
 *  - Opt-in: an agent with NO declared Allowlist is never flagged.
 *  - For each invoked tool/scope string, fnmatch against the active
 *    manifest's `tools`, `scopes`, and `deny` lists. A `deny` match is
 *    severity `violation`; a plain miss is severity `warn`.
 *  - Deduped per `(agent_id, tool, run_id)`: a repeat of the same
 *    (agent, tool, run) increments `detail.count` and does NOT open a new
 *    row.
 *  - Each new flag writes a companion `allowlist.drift` event row so the
 *    board's feed island can render the trail.
 */
class DetectDrift
{
    /**
     * @param  list<int>  $eventIds
     * @return list<DriftFlag>  Newly created drift flags (NOT the deduped repeats)
     */
    public function execute(array $eventIds): array
    {
        if (! (bool) config('tower.drift.enabled', true)) {
            return [];
        }

        if (empty($eventIds)) {
            return [];
        }

        $events = Event::query()
            ->whereIn('id', $eventIds)
            ->get();

        // Group (agent_id, run_id) -> [tool, tool, ...] so we can pull the
        // active allowlist once per agent instead of once per event.
        $byAgent = $this->collectInvocations($events);

        $newFlags = [];

        foreach ($byAgent as $agentId => $runs) {
            $agent = Agent::query()->find($agentId);
            if ($agent === null) {
                continue;
            }

            $allowlist = Allowlist::query()
                ->where('agent_id', $agent->id)
                ->orderByDesc('version')
                ->first();

            // No declared allowlist => drift is opt-in: never flagged.
            if ($allowlist === null) {
                continue;
            }

            $manifest = (array) $allowlist->manifest;
            $tools = (array) ($manifest['tools'] ?? []);
            $scopes = (array) ($manifest['scopes'] ?? []);
            $deny = (array) ($manifest['deny'] ?? []);

            foreach ($runs as $runId => $invocations) {
                // Events with no run map to a NULL run_id (drift_flags.run_id is
                // nullable); the '__none__' sentinel is loop-key-only.
                $dbRunId = $runId === '__none__' ? null : $runId;
                foreach ($invocations as $toolString => $meta) {
                    $severity = $this->classify($toolString, $tools, $scopes, $deny);
                    if ($severity === null) {
                        continue;
                    }

                    // Atomic dedup: bump an existing flag under a row lock, else
                    // insert. Partial unique indexes guarantee at most one row per
                    // (agent, tool, run) even under concurrent workers; a lost
                    // insert race is caught and folded into a bump.
                    if ($this->bumpExisting((string) $agent->id, $toolString, $dbRunId, $meta) !== null) {
                        continue;
                    }

                    try {
                        $flag = DB::transaction(function () use (
                            $agent, $allowlist, $dbRunId, $toolString, $severity, $meta,
                        ): DriftFlag {
                            $flag = DriftFlag::query()->create([
                                'agent_id' => $agent->id,
                                'run_id' => $dbRunId,
                                'allowlist_id' => $allowlist->id,
                                'tool' => $toolString,
                                'detail' => [
                                    'count' => (int) $meta['count'],
                                    'first_event_id' => (int) $meta['first_event_id'],
                                    'last_event_id' => (int) $meta['last_event_id'],
                                ],
                                'severity' => $severity,
                                'status' => DriftStatus::Open,
                            ]);

                            // Companion event for the feed island.
                            Event::query()->create([
                                'agent_id' => $agent->id,
                                'run_id' => $dbRunId,
                                'type' => EventType::AllowlistDrift,
                                'from_state' => null,
                                'to_state' => null,
                                'payload' => [
                                    'drift_flag_id' => $flag->id,
                                    'tool' => $toolString,
                                    'severity' => $severity->value,
                                ],
                                'source' => 'api',
                                'dedupe_key' => null,
                                'occurred_at' => now(),
                                'received_at' => now(),
                            ]);

                            return $flag;
                        });

                        $newFlags[] = $flag;
                    } catch (QueryException $e) {
                        if (! $this->isUniqueViolation($e)) {
                            throw $e;
                        }
                        // Lost the insert race: the row exists now — fold into a bump.
                        $this->bumpExisting((string) $agent->id, $toolString, $dbRunId, $meta);
                    }
                }
            }
        }

        return $newFlags;
    }

    /**
     * Walk the events; for each, pull the tool strings (from `tool.invoked`
     * directly, or from `run.state_changed payload.tools_used[]`).
     *
     * @return array<string, array<string, array{event_id:int}>>
     *   agentId => runId (or null) => tool => [event_id, ...]
     */
    /**
     * Bump an existing drift flag's count under a row lock (serializes
     * concurrent bumps). Returns the flag, or null if none exists yet.
     *
     * @param  array{count:int, first_event_id:int, last_event_id:int}  $meta
     */
    private function bumpExisting(string $agentId, string $tool, ?string $dbRunId, array $meta): ?DriftFlag
    {
        return DB::transaction(function () use ($agentId, $tool, $dbRunId, $meta): ?DriftFlag {
            $query = DriftFlag::query()
                ->where('agent_id', $agentId)
                ->where('tool', $tool)
                ->lockForUpdate();
            $dbRunId === null ? $query->whereNull('run_id') : $query->where('run_id', $dbRunId);
            $flag = $query->first();
            if ($flag === null) {
                return null;
            }

            $detail = (array) $flag->detail;
            $detail['count'] = (int) ($detail['count'] ?? 0) + (int) $meta['count'];
            $detail['last_event_id'] = (int) $meta['last_event_id'];
            $flag->forceFill(['detail' => $detail])->save();

            return $flag;
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres unique_violation SQLSTATE.
        return $e->getCode() === '23505'
            || (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23505');
    }

    private function collectInvocations(Collection $events): array
    {
        $byAgent = [];

        foreach ($events as $event) {
            $agentId = (string) $event->agent_id;
            $runId = $event->run_id !== null ? (string) $event->run_id : '__none__';
            $byAgent[$agentId] ??= [];

            $tools = [];
            if ($event->type === EventType::ToolInvoked) {
                $tool = (string) ($event->payload['tool']
                    ?? $event->payload['name']
                    ?? $event->payload['tool_name']
                    ?? '');
                if ($tool !== '') {
                    $tools[] = $tool;
                }
            } elseif ($event->type === EventType::RunStateChanged) {
                foreach ((array) ($event->payload['tools_used'] ?? []) as $t) {
                    if (is_string($t) && $t !== '') {
                        $tools[] = $t;
                    }
                }
            }

            // Accumulate per (agent, run, tool): repeats in the same batch bump
            // the count instead of overwriting (so dedup detail.count is right).
            foreach ($tools as $tool) {
                $slot = $byAgent[$agentId][$runId][$tool] ?? null;
                if ($slot === null) {
                    $byAgent[$agentId][$runId][$tool] = [
                        'count' => 1,
                        'first_event_id' => (int) $event->id,
                        'last_event_id' => (int) $event->id,
                    ];
                } else {
                    $slot['count']++;
                    $slot['last_event_id'] = (int) $event->id;
                    $byAgent[$agentId][$runId][$tool] = $slot;
                }
            }
        }

        return $byAgent;
    }

    /**
     * @param  list<string>  $tools
     * @param  list<string>  $scopes
     * @param  list<string>  $deny
     */
    private function classify(string $tool, array $tools, array $scopes, array $deny): ?DriftSeverity
    {
        foreach ($deny as $pattern) {
            if ($this->globMatch((string) $pattern, $tool)) {
                return DriftSeverity::Violation;
            }
        }

        foreach ($tools as $pattern) {
            if ($this->globMatch((string) $pattern, $tool)) {
                return null;
            }
        }
        foreach ($scopes as $pattern) {
            if ($this->globMatch((string) $pattern, $tool)) {
                return null;
            }
        }

        return DriftSeverity::Warn;
    }

    private function globMatch(string $pattern, string $value): bool
    {
        if ($pattern === $value) {
            return true;
        }
        return fnmatch($pattern, $value, FNM_NOESCAPE) === true;
    }
}
