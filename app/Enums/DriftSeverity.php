<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower drift severity — see docs/ARCHITECTURE.md §1.2 (drift_flags.severity).
 *
 * A `deny` manifest match is severity `violation`; a plain miss is `warn`.
 */
enum DriftSeverity: string
{
    case Warn = 'warn';
    case Violation = 'violation';
}
