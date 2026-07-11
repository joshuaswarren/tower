<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Enums\AgentStatus;
use App\Enums\RunState;
use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * Apply a state transition to a run (ARCHITECTURE.md §1.2 state machine).
 *
 * The contract is: an illegal transition is RECORDED as an event but
 * REJECTED as a transition. The run row is never silently reordered, and
 * the batch never crashes. `transition_rejected=true` on the event row is
 * the signal to the producer.
 *
 * On a legal transition we also maintain `started_at`, `ended_at`, and
 * `duration_ms` according to §1.2: `duration_ms` is computed at the
 * terminal transition.
 *
 * @phpstan-type TransitionResult array{
 *   from: ?RunState,
 *   to: ?RunState,
 *   rejected: bool,
 *   error: ?string,
 *   duration_ms: ?int,
 * }
 */
class ApplyRunTransition
{
    /**
     * @return TransitionResult
     */
    public function execute(
        Run $run,
        ?RunState $from,
        ?RunState $to,
        ?string $exitState,
        CarbonImmutable $occurredAt,
    ): array {
        $current = $run->state;

        // No target = no transition (heartbeat-shaped payload?); keep state.
        if ($to === null) {
            return [
                'from' => $current,
                'to' => $current,
                'rejected' => false,
                'error' => null,
                'duration_ms' => null,
            ];
        }

        // Self-transitions are rejected outright.
        if ($to === $current) {
            return [
                'from' => $current,
                'to' => $to,
                'rejected' => true,
                'error' => "self-transition {$current->value}->{$to->value}",
                'duration_ms' => null,
            ];
        }

        if (!$current->canTransitionTo($to)) {
            return [
                'from' => $current,
                'to' => $to,
                'rejected' => true,
                'error' => "illegal transition {$current->value}->{$to->value}",
                'duration_ms' => null,
            ];
        }

        // The model casts timestamps to (mutable) Carbon; normalize to
        // CarbonImmutable so an existing start is honored rather than
        // silently recomputed (which would zero out the duration).
        $startedAt = $run->started_at !== null
            ? CarbonImmutable::instance($run->started_at)
            : null;
        $endedAt = $to->isTerminal() ? $occurredAt : null;

        // With no recorded start yet, the first transition stamps started_at
        // from this event's clock.
        if ($startedAt === null) {
            $startedAt = $occurredAt;
        }

        $durationMs = null;
        if ($to->isTerminal() && $startedAt !== null) {
            $durationMs = max(0, (int) round($endedAt->getTimestamp() * 1000
                + (int) $endedAt->format('v') - $startedAt->getTimestamp() * 1000
                - (int) $startedAt->format('v')));
        }

        $run->forceFill([
            'state' => $to,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'exit_state' => $exitState,
        ])->save();

        return [
            'from' => $current,
            'to' => $to,
            'rejected' => false,
            'error' => null,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Map a run state to the agent's denormalized status (with one
     * exception: a "blocked" run keeps the agent working at the run level
     * but the agent is `blocked` per the §1.2 mapping). The
     * RunState -> AgentStatus mapping is intentionally narrow here.
     */
    public function mapRunStateToAgentStatus(RunState $state): AgentStatus
    {
        return match ($state) {
            RunState::Queued => AgentStatus::Idle,
            RunState::Running => AgentStatus::Working,
            RunState::Blocked => AgentStatus::Blocked,
            RunState::Done => AgentStatus::Done,
            RunState::Failed, RunState::Abandoned => AgentStatus::Done,
        };
    }
}
