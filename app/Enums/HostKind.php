<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower host kind — see docs/ARCHITECTURE.md §1.2 (hosts.kind).
 */
enum HostKind: string
{
    case Herdr = 'herdr';
    case Server = 'server';
    case Ci = 'ci';
    case Cloud = 'cloud';
    case Other = 'other';
}
