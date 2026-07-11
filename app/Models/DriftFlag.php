<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriftSeverity;
use App\Enums\DriftStatus;
use Database\Factories\DriftFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_id',
    'run_id',
    'allowlist_id',
    'tool',
    'detail',
    'severity',
    'status',
])]
class DriftFlag extends Model
{
    /** @use HasFactory<DriftFlagFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'severity' => DriftSeverity::class,
            'status' => DriftStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<Allowlist, $this>
     */
    public function allowlist(): BelongsTo
    {
        return $this->belongsTo(Allowlist::class);
    }
}
