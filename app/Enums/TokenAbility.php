<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower API token abilities — see docs/ARCHITECTURE.md §1.2 (api_tokens.abilities)
 * and §1.3 (per-endpoint ability requirements).
 */
enum TokenAbility: string
{
    case Ingest = 'ingest';
    case IngestHerdr = 'ingest:herdr';
    case Attest = 'attest';
}
