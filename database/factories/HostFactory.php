<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HostKind;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Host>
 */
class HostFactory extends Factory
{
    protected $model = Host::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'host-'.fake()->unique()->lexify('??????????'),
            'kind' => HostKind::Server,
            'connect_hint' => 'ssh '.fake()->userName(),
            'last_seen_at' => now(),
        ];
    }

    public function herdr(): static
    {
        return $this->state(fn () => ['kind' => HostKind::Herdr]);
    }

    public function ci(): static
    {
        return $this->state(fn () => ['kind' => HostKind::Ci]);
    }
}
