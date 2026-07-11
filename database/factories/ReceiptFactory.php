<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReceiptStatus;
use App\Models\Agent;
use App\Models\Receipt;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'agent_id' => Agent::factory(),
            'status' => ReceiptStatus::Unattested,
            'kind' => 'link',
            'url' => null,
            'summary' => null,
            'stub' => [
                'created_at' => now()->toIso8601String(),
                'source' => 'factory',
            ],
            'attested_by_type' => null,
            'attested_by_id' => null,
            'attested_at' => null,
        ];
    }

    public function forRun(Run $run): static
    {
        return $this->state(fn () => [
            'run_id' => $run->id,
            'agent_id' => $run->agent_id,
        ]);
    }

    public function attested(string $url = 'https://example.com/artifact'): static
    {
        return $this->state(fn () => [
            'status' => ReceiptStatus::Attested,
            'url' => $url,
            'summary' => 'attested via factory',
            'attested_at' => now(),
        ]);
    }
}
