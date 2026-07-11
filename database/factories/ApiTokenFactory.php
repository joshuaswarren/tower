<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = 'twr_'.Str::lower(Str::random(40));

        return [
            'name' => 'token-'.fake()->word(),
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
            'owner_type' => Agent::class,
            'owner_id' => Agent::factory(),
            'abilities' => [TokenAbility::Ingest->value],
            'last_used_at' => null,
        ];
    }

    /**
     * @return $this
     */
    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => [
            'owner_type' => Agent::class,
            'owner_id' => $agent->id,
        ]);
    }

    public function forHost(Host $host): static
    {
        return $this->state(fn () => [
            'owner_type' => Host::class,
            'owner_id' => $host->id,
        ]);
    }

    /**
     * @param  list<TokenAbility|string>  $abilities
     */
    public function withAbilities(array $abilities): static
    {
        return $this->state(fn () => [
            'abilities' => array_map(
                fn (TokenAbility|string $a) => $a instanceof TokenAbility ? $a->value : $a,
                $abilities
            ),
        ]);
    }
}
