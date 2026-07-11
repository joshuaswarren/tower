<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower agent kind — see docs/ARCHITECTURE.md §1.2 (agents.kind).
 */
enum AgentKind: string
{
    case Omp = 'omp';
    case Openclaw = 'openclaw';
    case ClaudeCode = 'claude-code';
    case Codex = 'codex';
    case Herdr = 'herdr';
    case Ci = 'ci';
    case Demo = 'demo';
    case Other = 'other';
}
