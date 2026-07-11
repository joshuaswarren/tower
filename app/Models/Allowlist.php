<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AllowlistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_id',
    'version',
    'manifest',
    'declared_at',
])]
class Allowlist extends Model
{
    /** @use HasFactory<AllowlistFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'manifest' => 'array',
            'declared_at' => 'datetime',
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
     * Highest-version allowlist per agent is the active manifest. Subqueries
     * over (agent_id, MAX(version)) to avoid a window function — keeps the
     * scope straightforwardly composable.
     *
     * @param  Builder<Allowlist>  $query
     * @return Builder<Allowlist>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn(
            'version',
            Allowlist::query()->selectRaw('max(version) as max_version')->groupBy('agent_id')
        );
    }
}
