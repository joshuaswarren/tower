<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower run state machine — see docs/ARCHITECTURE.md §1.2 (runs.state).
 *
 * Transitions (enforced by `canTransitionTo`):
 *   queued    -> running
 *   running   <-> blocked
 *   running   -> done | failed | abandoned
 *   blocked   -> done | failed | abandoned
 *   done | failed | abandoned are terminal (no outbound edges).
 *
 * Illegal transitions are recorded as events with
 * `payload.transition_rejected = true` — they are never silently reordered
 * and never crash a batch.
 */
enum RunState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Blocked = 'blocked';
    case Done = 'done';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    /**
     * Terminal states are final — no outbound transitions are allowed.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Abandoned => true,
            self::Queued, self::Running, self::Blocked => false,
        };
    }

    /**
     * Whether `$this` may transition to `$target` under the state machine in
     * ARCHITECTURE.md §1.2. A self-transition is rejected (no run.state_changed
     * event should be emitted for `running -> running`).
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Queued => $target === self::Running,
            self::Running => $target === self::Blocked
                || $target === self::Done
                || $target === self::Failed
                || $target === self::Abandoned,
            self::Blocked => $target === self::Running
                || $target === self::Done
                || $target === self::Failed
                || $target === self::Abandoned,
            self::Done, self::Failed, self::Abandoned => false,
        };
    }
}
