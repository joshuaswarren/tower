<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower agent status — see docs/ARCHITECTURE.md §1.2 (agents.status).
 *
 * Mirrors herdr's four semantic states (idle/working/blocked/done) and adds a
 * derived `offline` value written by the staleness sweep. Never written by
 * controllers directly — only by ApplyRunTransition, the heartbeat handler,
 * and tower:sweep-stale.
 */
enum AgentStatus: string
{
    case Idle = 'idle';
    case Working = 'working';
    case Blocked = 'blocked';
    case Done = 'done';
    case Offline = 'offline';
}
