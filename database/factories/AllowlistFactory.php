<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Allowlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Allowlist>
 */
class AllowlistFactory extends Factory
{
    protected $model = Allowlist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'version' => 1,
            'manifest' => [
                'tools' => ['bash', 'edit', 'grep'],
                'scopes' => ['repo:tower'],
                'deny' => ['prod:*'],
            ],
            'declared_at' => now(),
        ];
    }

    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => ['agent_id' => $agent->id]);
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }
}
