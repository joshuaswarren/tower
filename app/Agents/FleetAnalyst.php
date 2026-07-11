<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\EventType;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\Event;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FleetAnalyst — the MUST-ship demo agent (docs/ARCHITECTURE.md §1.7).
 *
 * Reads real board data for the last 24 hours and assembles a prompt
 * the narrator will stream back. The prompt itself is the only
 * deliverable of this class — the actual streaming, broadcasting,
 * run lifecycle, and receipt attestation are the job of
 * `App\Jobs\RunDemoAgent` (which calls
 * `App\Support\Demo\DemoNarrator::stream`).
 *
 * Reading the real DB (not a fixture) is part of the demo's
 * honesty: visitors on the public board see the same numbers we
 * show in the running stream.
 */
class FleetAnalyst
{
    public function __construct(
        private readonly int $windowHours = 24,
    ) {
    }

    /**
     * Build the prompt the narrator will consume. Pure function of the
     * last `$windowHours` of board data + the agent's own meta
     * (so the report can identify itself).
     */
    public function buildPrompt(?string $agentName = null): string
    {
        $since = CarbonImmutable::now()->subHours($this->windowHours);

        $busiest = $this->busiestAgent($since);
        $blocked = $this->blockedSummary($since);
        $drift = $this->driftSummary($since);

        $lines = [
            'You are FleetAnalyst, a board-watching assistant. '
                .'Write a 2-paragraph, plain-English briefing about the '
                .'Tower fleet for the last '.$this->windowHours.' hours. '
                .'Do NOT invent numbers. Use ONLY the data below.',
            '',
            'Window since: '.$since->toIso8601String(),
            'Now:         '.CarbonImmutable::now()->toIso8601String(),
            '',
            'Busiest agent (by event count):',
            $this->formatRow($busiest),
            '',
            'Blocked time (running -> blocked -> running/done/...):',
            $this->formatBlocked($blocked),
            '',
            'Drift summary (last '.$this->windowHours.'h):',
            $this->formatDrift($drift),
            '',
            'Output: a short briefing, no bullet lists, no JSON.',
        ];

        if ($agentName !== null && $agentName !== '') {
            $lines[] = '';
            $lines[] = 'Self-reference: you are running on agent ['.$agentName.'].';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{agent: ?Agent, event_count: int}
     */
    private function busiestAgent(CarbonImmutable $since): array
    {
        $row = DB::table('events')
            ->select('agent_id', DB::raw('count(*) as event_count'))
            ->where('received_at', '>=', $since)
            ->groupBy('agent_id')
            ->orderByDesc('event_count')
            ->limit(1)
            ->first();

        if ($row === null) {
            return ['agent' => null, 'event_count' => 0];
        }

        return [
            'agent' => Agent::query()->find($row->agent_id),
            'event_count' => (int) $row->event_count,
        ];
    }

    /**
     * Sum of `blocked` time (in seconds) for every run that passed
     * through `running -> blocked -> ...` in the window. Approximate:
     * we pair each `running -> blocked` event with the next non-blocked
     * transition on the same run within the window.
     *
     * @return array{blocked_seconds: int, agent_count: int}
     */
    private function blockedSummary(CarbonImmutable $since): array
    {
        $blockedEntries = Event::query()
            ->where('type', EventType::RunStateChanged)
            ->where('to_state', RunState::Blocked)
            ->where('received_at', '>=', $since)
            ->orderBy('received_at')
            ->get(['id', 'run_id', 'received_at']);

        $totalMs = 0;
        $agentIds = [];

        foreach ($blockedEntries as $entry) {
            if ($entry->run_id === null) {
                continue;
            }
            $exit = Event::query()
                ->where('run_id', $entry->run_id)
                ->where('id', '>', $entry->id)
                ->where('type', EventType::RunStateChanged)
                ->where('to_state', '!=', RunState::Blocked)
                ->orderBy('id')
                ->first(['id', 'received_at', 'agent_id']);
            if ($exit === null || $exit->received_at === null) {
                continue;
            }
            $totalMs += max(0, $exit->received_at->getTimestampMs() - $entry->received_at->getTimestampMs());
            if ($exit->agent_id !== null) {
                $agentIds[(string) $exit->agent_id] = true;
            }
        }

        return [
            'blocked_seconds' => (int) round($totalMs / 1000),
            'agent_count' => count($agentIds),
        ];
    }

    /**
     * @return array{warnings: int, violations: int, open: int}
     */
    private function driftSummary(CarbonImmutable $since): array
    {
        $rows = DB::table('drift_flags')
            ->select('severity', 'status', DB::raw('count(*) as c'))
            ->where('created_at', '>=', $since)
            ->groupBy('severity', 'status')
            ->get();

        $warnings = 0;
        $violations = 0;
        $open = 0;
        foreach ($rows as $row) {
            $count = (int) $row->c;
            if ($row->severity === 'violation') {
                $violations += $count;
            } else {
                $warnings += $count;
            }
            if ($row->status === 'open') {
                $open += $count;
            }
        }

        return [
            'warnings' => $warnings,
            'violations' => $violations,
            'open' => $open,
        ];
    }

    /**
     * @param  array{agent: ?Agent, event_count: int}  $row
     */
    private function formatRow(array $row): string
    {
        if ($row['agent'] === null) {
            return '  (no events in window)';
        }
        $name = $row['agent']->name ?? '(unnamed)';
        return "  - {$name} (id={$row['agent']->id}): {$row['event_count']} events";
    }

    /**
     * @param  array{blocked_seconds: int, agent_count: int}  $blocked
     */
    private function formatBlocked(array $blocked): string
    {
        return sprintf('  - %d seconds blocked across %d agent(s)',
            $blocked['blocked_seconds'], $blocked['agent_count']);
    }

    /**
     * @param  array{warnings: int, violations: int, open: int}  $drift
     */
    private function formatDrift(array $drift): string
    {
        return sprintf(
            '  - %d warning(s), %d violation(s), %d open',
            $drift['warnings'], $drift['violations'], $drift['open'],
        );
    }

    /**
     * Convenience: every demo run also needs a fresh `external_id` so
     * the ingest path can upsert deterministically. We return
     * a `run:{ulid}` string here; the job composes it with the agent
     * id for the unique constraint.
     */
    public static function newExternalId(): string
    {
        return 'demo:'.(string) \Illuminate\Support\Str::ulid();
    }
}
