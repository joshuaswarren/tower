<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RunState;
use App\Models\Agent;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    protected $model = Run::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'external_id' => 'run-'.fake()->unique()->lexify('??????????'),
            'title' => 'Run '.fake()->sentence(3),
            'state' => RunState::Queued,
            'started_at' => null,
            'ended_at' => null,
            'duration_ms' => null,
            'exit_state' => null,
            'meta' => ['source' => 'factory'],
        ];
    }

    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => ['agent_id' => $agent->id]);
    }

    public function queued(): static
    {
        return $this->state(fn () => ['state' => RunState::Queued]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'state' => RunState::Running,
            'started_at' => now()->subMinutes(5),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'state' => RunState::Blocked,
            'started_at' => now()->subMinutes(5),
        ]);
    }

    public function done(int $durationMs = 60_000): static
    {
        return $this->state(fn () => [
            'state' => RunState::Done,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'duration_ms' => $durationMs,
            'exit_state' => 'success',
        ]);
    }

    public function failed(string $reason = 'error:generic'): static
    {
        return $this->state(fn () => [
            'state' => RunState::Failed,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'exit_state' => $reason,
        ]);
    }
}
