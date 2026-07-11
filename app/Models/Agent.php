<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentKind;
use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'host_id',
    'name',
    'kind',
    'status',
    'last_heartbeat_at',
    'last_event_at',
    'meta',
])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => AgentKind::class,
            'status' => AgentStatus::class,
            'last_heartbeat_at' => 'datetime',
            'last_event_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Allowlist, $this>
     */
    public function allowlists(): HasMany
    {
        return $this->hasMany(Allowlist::class);
    }

    /**
     * @return HasMany<DriftFlag, $this>
     */
    public function driftFlags(): HasMany
    {
        return $this->hasMany(DriftFlag::class);
    }

    /**
     * Agents whose `last_heartbeat_at` is older than
     * `config('tower.staleness.offline_seconds')` are considered stale —
     * tower:sweep-stale moves them to AgentStatus::Offline.
     *
     * Note: agents with a NULL heartbeat are not considered stale (they may
     * have just been created). The sweep sets the first heartbeat.
     *
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    public function scopeStale(Builder $query): Builder
    {
        $threshold = (int) config('tower.staleness.offline_seconds');
        $cutoff = now()->subSeconds(max($threshold, 0));

        return $query->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', $cutoff);
    }
}
