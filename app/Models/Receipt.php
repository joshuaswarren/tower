<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReceiptStatus;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'run_id',
    'agent_id',
    'status',
    'kind',
    'url',
    'summary',
    'stub',
    'attested_by_type',
    'attested_by_id',
    'attested_at',
])]
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'stub' => 'array',
            'attested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * User session or ApiToken (must hold the `attest` ability) that
     * attested this receipt. Immutably set at the unattested -> attested
     * transition.
     *
     * @return MorphTo<Model, $this>
     */
    public function attestedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
