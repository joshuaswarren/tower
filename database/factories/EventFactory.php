<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventType;
use App\Models\Agent;
use App\Models\Event;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurred = now()->subSeconds(fake()->numberBetween(0, 300));

        return [
            'agent_id' => Agent::factory(),
            'run_id' => null,
            'type' => EventType::LogNote,
            'from_state' => null,
            'to_state' => null,
            'payload' => ['note' => fake()->sentence()],
            'source' => 'api',
            'dedupe_key' => null,
            'occurred_at' => $occurred,
            'received_at' => now(),
        ];
    }

    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => ['agent_id' => $agent->id]);
    }

    public function forRun(Run $run): static
    {
        return $this->state(fn () => [
            'run_id' => $run->id,
            'agent_id' => $run->agent_id,
        ]);
    }

    public function ofType(EventType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function runStateChanged(string $from, string $to): static
    {
        return $this->state(fn () => [
            'type' => EventType::RunStateChanged,
            'from_state' => $from,
            'to_state' => $to,
        ]);
    }

    public function heartbeat(): static
    {
        return $this->state(fn () => ['type' => EventType::AgentHeartbeat]);
    }

    public function withDedupeKey(string $key): static
    {
        return $this->state(fn () => ['dedupe_key' => $key]);
    }
}
