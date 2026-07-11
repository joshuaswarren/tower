<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgentStatus;
use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use App\Enums\EventType;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\DriftFlag;
use App\Models\Event;
use App\Models\Host;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A coherent, clearly-labelled PUBLIC demo workspace so the board (and any
 * fork) renders something real without a live producer. Shows every agent
 * status, at least one blocked agent, an open drift flag, and both receipt
 * states. All names are prefixed `demo-` so nothing reads as private fleet.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $host = Host::query()->firstOrCreate(
            ['name' => 'demo-box'],
            ['kind' => 'server', 'connect_hint' => 'ssh demo-box', 'last_seen_at' => now()],
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['host_id' => $host->id, 'name' => 'demo'],
            ['visibility' => Workspace::VISIBILITY_PUBLIC],
        );

        // Idempotent: if the demo workspace is already populated, do nothing
        // (reseeds and redeploys rerun seeders; duplicate synthetic agents
        // would make the public demo misleading).
        if (Agent::query()->where('workspace_id', $workspace->id)->exists()) {
            $this->command?->info('Demo workspace already seeded; skipping.');

            return;
        }

        $statuses = [
            AgentStatus::Working, AgentStatus::Blocked, AgentStatus::Idle,
            AgentStatus::Done, AgentStatus::Offline, AgentStatus::Blocked,
        ];

        // All-or-nothing: a partial failure rolls back entirely, so the
        // agent-count guard above always sees either a complete seed or none.
        DB::transaction(function () use ($workspace, $host, $statuses): void {
        foreach ($statuses as $i => $status) {
            $agent = Agent::factory()->create([
                'workspace_id' => $workspace->id,
                'host_id' => $host->id,
                'name' => "demo-agent-{$i}",
                'kind' => 'demo',
                'status' => $status,
                'last_heartbeat_at' => now()->subMinutes($i),
                'last_event_at' => now()->subMinutes($i * 2),
            ]);

            $run = Run::factory()->forAgent($agent)->create([
                'title' => "demo run {$i}",
                'state' => $status === AgentStatus::Done ? 'done' : 'running',
                'started_at' => now()->subMinutes(30),
                'ended_at' => $status === AgentStatus::Done ? now()->subMinutes(5) : null,
                'duration_ms' => $status === AgentStatus::Done ? 1_500_000 : null,
            ]);

            // Two dozen events per agent for a believable feed.
            Event::factory()->count(24)->create([
                'agent_id' => $agent->id,
                'run_id' => $run->id,
                'type' => EventType::LogNote,
            ]);

            if ($status === AgentStatus::Done) {
                Receipt::factory()->forRun($run)->attested('https://example.com/demo/pr-'.$i)->create();
            }
        }

        // One unattested receipt (honesty model on display).
        $pending = Agent::query()->where('workspace_id', $workspace->id)->first();
        if ($pending !== null) {
            $pendingRun = Run::factory()->forAgent($pending)->create(['state' => 'done']);
            Receipt::factory()->forRun($pendingRun)->create(['url' => null]);
        }

        // One declared allowlist + an open drift flag on a blocked agent.
        $blocked = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', AgentStatus::Blocked->value)
            ->first();
        if ($blocked !== null) {
            $allowlist = Allowlist::factory()->forAgent($blocked)->create([
                'version' => 1,
                'manifest' => ['tools' => ['bash', 'edit'], 'scopes' => [], 'deny' => ['prod:*']],
            ]);
            DriftFlag::query()->create([
                'agent_id' => $blocked->id,
                'run_id' => null,
                'allowlist_id' => $allowlist->id,
                'tool' => 'curl',
                'detail' => ['count' => 2, 'first_event_id' => 0, 'last_event_id' => 0],
                'severity' => DriftSeverity::Warn,
                'status' => DriftStatus::Open,
            ]);
        }
        });

        $this->command?->info('Demo workspace seeded (public).');
    }
}
