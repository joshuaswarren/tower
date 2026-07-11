<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HostKind;
use Database\Factories\HostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'kind', 'connect_hint', 'last_seen_at'])]
class Host extends Model
{
    /** @use HasFactory<HostFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => HostKind::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
