<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Host;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_id' => Host::factory(),
            'name' => 'ws-'.fake()->unique()->lexify('??????????'),
            'visibility' => Workspace::VISIBILITY_PRIVATE,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => Workspace::VISIBILITY_PUBLIC]);
    }

    public function onHost(Host $host): static
    {
        return $this->state(fn () => ['host_id' => $host->id]);
    }
}
