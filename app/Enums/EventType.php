<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower event types — see docs/ARCHITECTURE.md §1.2 (events.type) and
 * docs/contracts/tower.ingest.v1.json (event.type enum).
 */
enum EventType: string
{
    case RunStateChanged = 'run.state_changed';
    case AgentHeartbeat = 'agent.heartbeat';
    case ToolInvoked = 'tool.invoked';
    case AllowlistDeclared = 'allowlist.declared';
    case AllowlistDrift = 'allowlist.drift';
    case ReceiptPosted = 'receipt.posted';
    case ReceiptAttested = 'receipt.attested';
    case LogNote = 'log.note';
}
