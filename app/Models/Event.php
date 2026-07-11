<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventType;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event log. Uses the legacy PHP `Event` name (the file is
 * `Event.php`, the class is `Event`) — referenced everywhere as
 * `App\Models\Event`. To avoid colliding with framework `Event` facades the
 * class is namespaced; the model short name is `Event` and the
 * `EventModel` alias below gives a non-conflicting import for type-hints.
 */
#[Fillable([
    'agent_id',
    'run_id',
    'type',
    'from_state',
    'to_state',
    'payload',
    'source',
    'dedupe_key',
    'occurred_at',
    'received_at',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
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
}
