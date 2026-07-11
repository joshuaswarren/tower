<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower drift status — see docs/ARCHITECTURE.md §1.2 (drift_flags.status).
 */
enum DriftStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
