<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Host;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'host_id' => Host::factory(),
            'name' => 'agent-'.fake()->unique()->lexify('??????????'),
            'kind' => AgentKind::Omp,
            'status' => AgentStatus::Idle,
            'last_heartbeat_at' => now(),
            'last_event_at' => null,
            'meta' => ['lane' => 'lane-'.fake()->word()],
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn () => [
            'workspace_id' => $workspace->id,
            'host_id' => $workspace->host_id,
        ]);
    }

    public function working(): static
    {
        return $this->state(fn () => ['status' => AgentStatus::Working]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => AgentStatus::Blocked]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => AgentStatus::Done]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => AgentStatus::Offline,
            'last_heartbeat_at' => now()->subHours(1),
        ]);
    }

    public function heartbeatAt(\DateTimeInterface|string|null $when): static
    {
        return $this->state(fn () => ['last_heartbeat_at' => $when]);
    }
}
