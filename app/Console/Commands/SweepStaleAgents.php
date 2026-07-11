<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AgentStatus;
use App\Events\Board\AgentStatusChanged;
use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `tower:sweep-stale`
 *
 * Scheduled every minute. Flips any agent whose `last_heartbeat_at` is
 * older than `config('tower.staleness.offline_seconds')` to
 * `AgentStatus::Offline` and broadcasts `AgentStatusChanged` so the
 * `attention` and `grid` islands refresh.
 *
 * This is the "board never lies green" guarantee (§1.2).
 */
class SweepStaleAgents extends Command
{
    protected $signature = 'tower:sweep-stale';

    protected $description = 'Sweep agents with stale heartbeats to `offline` and broadcast the change.';

    public function handle(): int
    {
        $stale = Agent::stale()->get();
        if ($stale->isEmpty()) {
            $this->info('No stale agents.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($stale as $agent) {
            DB::transaction(function () use ($agent): void {
                $agent->forceFill([
                    'status' => AgentStatus::Offline,
                    'last_event_at' => now(),
                ])->save();
            });

            $workspaceId = (string) DB::table('agents')
                ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
                ->where('agents.id', $agent->id)
                ->value('workspaces.id');
            $public = (string) DB::table('agents')
                ->join('workspaces', 'workspaces.id', '=', 'agents.workspace_id')
                ->where('agents.id', $agent->id)
                ->value('workspaces.visibility') === \App\Models\Workspace::VISIBILITY_PUBLIC;

            try {
                AgentStatusChanged::dispatch(
                    (string) $agent->id,
                    AgentStatus::Offline,
                    $workspaceId ?? '',
                    $public,
                );
            } catch (\Throwable $e) {
                report($e);
            }
            $count++;
        }

        $this->info("Swept {$count} stale agent(s) to offline.");
        return self::SUCCESS;
    }
}
