<?php

declare(strict_types=1);

namespace App\Events\Board;

use App\Enums\AgentStatus;

/**
 * broadcastAs: `agent.status_changed` — refreshes islands `grid`, `attention`.
 */
final class AgentStatusChanged extends BoardBroadcast
{
    public function __construct(
        public readonly string $agentId,
        public readonly AgentStatus $status,
        string $workspaceId,
        bool $workspacePublic,
    ) {
        parent::__construct($workspaceId, $workspacePublic);
    }

    public function broadcastAs(): string
    {
        return 'agent.status_changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agentId,
            'status' => $this->status->value,
            'workspace_id' => $this->workspaceId,
        ];
    }
}
