<?php

declare(strict_types=1);

namespace App\Events\Board;

use App\Enums\DriftSeverity;

/**
 * broadcastAs: `drift.raised` — refreshes islands `attention`, `feed`.
 */
final class DriftFlagRaised extends BoardBroadcast
{
    public function __construct(
        public readonly string $driftFlagId,
        public readonly string $agentId,
        public readonly DriftSeverity $severity,
        string $workspaceId,
        bool $workspacePublic,
    ) {
        parent::__construct($workspaceId, $workspacePublic);
    }

    public function broadcastAs(): string
    {
        return 'drift.raised';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'drift_flag_id' => $this->driftFlagId,
            'agent_id' => $this->agentId,
            'severity' => $this->severity->value,
            'workspace_id' => $this->workspaceId,
        ];
    }
}
