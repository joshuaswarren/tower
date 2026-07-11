<?php

declare(strict_types=1);

namespace App\Events\Board;

use App\Enums\RunState;

/**
 * broadcastAs: `run.updated` — refreshes islands `grid`, `feed`.
 */
final class RunUpdated extends BoardBroadcast
{
    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly RunState $state,
        string $workspaceId,
        bool $workspacePublic,
    ) {
        parent::__construct($workspaceId, $workspacePublic);
    }

    public function broadcastAs(): string
    {
        return 'run.updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'agent_id' => $this->agentId,
            'state' => $this->state->value,
            'workspace_id' => $this->workspaceId,
        ];
    }
}
