<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use App\Models\Agent;
use App\Models\DriftFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriftFlag>
 */
class DriftFlagFactory extends Factory
{
    protected $model = DriftFlag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'run_id' => null,
            'allowlist_id' => null,
            'tool' => 'bash',
            'detail' => ['count' => 1, 'event_id' => null],
            'severity' => DriftSeverity::Warn,
            'status' => DriftStatus::Open,
        ];
    }

    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => ['agent_id' => $agent->id]);
    }

    public function violation(): static
    {
        return $this->state(fn () => ['severity' => DriftSeverity::Violation]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => ['status' => DriftStatus::Acknowledged]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => DriftStatus::Resolved]);
    }
}
