<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunState;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agent_id',
    'external_id',
    'title',
    'state',
    'started_at',
    'ended_at',
    'duration_ms',
    'exit_state',
    'meta',
])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'state' => RunState::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_ms' => 'integer',
            'meta' => 'array',
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
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
