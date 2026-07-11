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
                foreach ($invocations as $toolString) {
                    $severity = $this->classify($toolString, $tools, $scopes, $deny);
                    if ($severity === null) {
                        continue;
                    }

                    // Dedup: same (agent, tool, run) row => bump count, no new flag.
                    $existing = DriftFlag::query()
                        ->where('agent_id', $agent->id)
                        ->where('run_id', $runId)
                        ->where('tool', $toolString)
                        ->first();

                    if ($existing !== null) {
                        $detail = (array) $existing->detail;
                        $detail['count'] = (int) ($detail['count'] ?? 0) + 1;
                        $detail['last_event_id'] = (int) $invocations[$toolString]['event_id'];
                        $existing->forceFill(['detail' => $detail])->save();
                        continue;
                    }

                    $flag = DB::transaction(function () use (
                        $agent, $allowlist, $runId, $toolString, $severity, $invocations,
                    ): DriftFlag {
                        $firstInvocation = $invocations[$toolString];
                        $flag = DriftFlag::query()->create([
                            'agent_id' => $agent->id,
                            'run_id' => $runId,
                            'allowlist_id' => $allowlist->id,
                            'tool' => $toolString,
                            'detail' => [
                                'count' => 1,
                                'first_event_id' => (int) $firstInvocation['event_id'],
                                'last_event_id' => (int) $firstInvocation['event_id'],
                            ],
                            'severity' => $severity,
                            'status' => DriftStatus::Open,
                        ]);

                        // Companion event for the feed island.
                        Event::query()->create([
                            'agent_id' => $agent->id,
                            'run_id' => $runId,
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
    private function collectInvocations(Collection $events): array
    {
        $byAgent = [];

        foreach ($events as $event) {
            $agentId = (string) $event->agent_id;
            $runId = $event->run_id !== null ? (string) $event->run_id : '__none__';
            $byAgent[$agentId] ??= [];

            if ($event->type === EventType::ToolInvoked) {
                $tool = (string) ($event->payload['tool'] ?? $event->payload['name'] ?? '');
                if ($tool === '') {
                    $tool = (string) ($event->payload['tool_name'] ?? '');
                }
                if ($tool === '') {
                    continue;
                }
                $byAgent[$agentId][$runId][$tool] = ['event_id' => (int) $event->id];
            } elseif ($event->type === EventType::RunStateChanged) {
                $toolsUsed = (array) ($event->payload['tools_used'] ?? []);
                foreach ($toolsUsed as $tool) {
                    if (!is_string($tool) || $tool === '') {
                        continue;
                    }
                    $byAgent[$agentId][$runId][$tool] = ['event_id' => (int) $event->id];
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
