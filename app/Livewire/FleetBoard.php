<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AgentStatus;
use App\Enums\DriftStatus;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\DriftFlag;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The mission-control board. One component, two mount modes:
 *
 * - authed: `/board` (route name `board`) — sees every workspace and
 *   every agent, with all admin affordances (acknowledge/resolve drift,
 *   attest receipts, run detail links). Subscribes to `fleet.board`
 *   client-side via the JS bridge.
 * - public: `/` (route name `public.board`) — query-level filtered to
 *   `visibility=public` workspaces; NO private identifiers rendered;
 *   subscribes to `public.board` only.
 *
 * Island layout (per docs/ARCHITECTURE.md §1.4 + docs/contracts/channels.md):
 *   - `attention` (eager) — blocked agents + open drift, oldest-first
 *   - `grid` (eager) — host → workspace → agent tree with active run
 *   - `feed` (lazy, append mode) — recent events
 *   - `receipts` (lazy) — recent receipts
 *
 * Every island also carries `wire:poll.30s` as a degraded-mode fallback
 * (ADR-0004) — if Reverb dies the board stays correct, just less
 * realtime.
 */
#[Layout('components.layouts.app')]
#[Title('Board')]
final class FleetBoard extends Component
{
    /**
     * Detect public vs authed mode from the current route name. The
     * route at `/` is named `public.board`; the route at `/board` is
     * named `board`. The mode is resolved once at mount time and is
     * the same for every island and every subsequent Livewire call
     * within the request lifecycle.
     *
     * If we're rendering the public route while the feature is
     * feature-flagged off, redirect to login (single source of
     * truth for the gate — the JS bridge never sees the disabled
     * state).
     */
    public function mount(): void
    {
        $routeName = request()->route()?->getName();
        $this->mode = $routeName === 'public.board' ? 'public' : 'authed';

        if ($this->mode === 'public'
            && ! (bool) config('tower.public_board.enabled', true)) {
            $this->redirectRoute('login');
        }
    }

    /**
     * Convenience: the public board subscribes to `public.board`; the
     * authed board subscribes to `fleet.board`. The JS bridge reads
     * this from the rendered HTML to know which channel to listen on.
     */
    #[Computed]
    public function broadcastChannel(): string
    {
        return $this->mode === 'public' ? 'public.board' : 'fleet.board';
    }

    /**
     * Whether this mount is the public board. Surfaced as a computed
     * so the island scopes (which have their own render scope and
     * therefore do not see the render() data array) can read it
     * directly via `$this->isPublic`.
     */
    #[Computed]
    public function isPublic(): bool
    {
        return $this->mode === 'public';
    }

    /**
     * Attention island — blocked agents first (oldest block first), then
     * open drift flags. The phone sees this as the first viewport; it
     * IS the "blocked on approval" surface.
     *
     * @return Collection<int, array{label: string, kind: 'agent'|'drift', id: string, since: string, detail: string, severity: string|null}>
     */
    #[Computed]
    public function attentionItems(): Collection
    {
        $now = now();

        $blockedAgents = $this->baseAgentQuery()
            ->where('status', AgentStatus::Blocked->value)
            ->with('workspace:id,name')
            ->orderBy('last_event_at')   // oldest block first
            ->orderBy('last_heartbeat_at')
            ->limit(20)
            ->get()
            ->map(function (Agent $agent) use ($now): array {
                $since = $agent->last_event_at
                    ?? $agent->last_heartbeat_at
                    ?? $agent->updated_at;
                $duration = $since ? $since->diffForHumans($now, ['parts' => 2, 'short' => true]) : 'just now';

                return [
                    'label' => $agent->name,
                    'kind' => 'agent',
                    'id' => $agent->id,
                    'since' => $duration,
                    'detail' => $agent->workspace?->name ?? 'no workspace',
                    'severity' => null,
                ];
            });

        $openDrift = $this->baseDriftQuery()
            ->where('status', DriftStatus::Open->value)
            ->with(['agent:id,name,workspace_id', 'agent.workspace:id,name'])
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(function (DriftFlag $flag): array {
                $detail = $flag->agent?->name ?? 'unknown agent';
                if ($flag->agent?->workspace) {
                    $detail .= ' · '.$flag->agent->workspace->name;
                }
                $detail .= ' · tool: '.$flag->tool;

                return [
                    'label' => $flag->tool,
                    'kind' => 'drift',
                    'id' => $flag->id,
                    'since' => $flag->created_at?->diffForHumans() ?? 'just now',
                    'detail' => $detail,
                    'severity' => $flag->severity->value,
                ];
            });

        // Blocked agents first, then drift — a blocked agent is the
        // thing the admin actually has to act on.
        return $blockedAgents->concat($openDrift)->values();
    }

    /**
     * Grid island — host → workspace → agent tree, with the active run
     * title (if any) and a copy-paste `connect_hint` affordance per host.
     * Status colors map to the 5-state enum; `offline` is rendered muted
     * to communicate staleness.
     *
     * @return Collection<int, array{name: string, connect_hint: string|null, workspaces: Collection<int, array{name: string, visibility: string, agents: Collection<int, array<string, mixed>>}>}>
     */
    #[Computed]
    public function gridGroups(): Collection
    {
        $hosts = \App\Models\Host::query()
            ->with([
                'workspaces' => function (HasMany $q): void {
                    $q->whereIn('id', $this->baseWorkspaceQuery()->select('id'))
                        ->orderBy('name');
                },
                'workspaces.agents' => function (HasMany $q): void {
                    $q->orderBy('name');
                },
                'workspaces.agents.runs' => function (HasMany $q): void {
                    $q->whereIn('state', [RunState::Running->value, RunState::Blocked->value, RunState::Queued->value])
                        ->latest('started_at')
                        ->limit(1);
                },
            ])
            ->whereHas('workspaces', function (Builder $q): void {
                $q->whereIn('id', $this->baseWorkspaceQuery()->select('id'));
            })
            ->orderBy('name')
            ->get();

        return $hosts->map(function ($host): array {
            return [
                'name' => $host->name,
                'connect_hint' => $host->connect_hint,
                'workspaces' => $host->workspaces->map(function (Workspace $ws): array {
                    return [
                        'name' => $ws->name,
                        'visibility' => $ws->visibility->value ?? (string) $ws->visibility,
                        'agents' => $ws->agents->map(function (Agent $agent): array {
                            $active = $agent->runs->first();
                            $status = $agent->status instanceof AgentStatus
                                ? $agent->status->value
                                : (string) $agent->status;

                            return [
                                'id' => $agent->id,
                                'name' => $agent->name,
                                'kind' => $agent->kind instanceof \BackedEnum
                                    ? $agent->kind->value
                                    : (string) $agent->kind,
                                'status' => $status,
                                'active_run_title' => $active?->title,
                                'active_run_state' => $active?->state instanceof RunState
                                    ? $active->state->value
                                    : ($active?->state ? (string) $active->state : null),
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values();
    }

    /**
     * Feed island — recent events, newest first. In the authed board this
     * is unbounded by visibility. The public board still gets the full
     * event log for public-workspace activity.
     *
     * @return Collection<int, \App\Models\Event>
     */
    #[Computed]
    public function feedEvents(): Collection
    {
        $eventAgentIds = $this->baseAgentQuery()->select('agents.id');

        return \App\Models\Event::query()
            ->whereIn('agent_id', $eventAgentIds)
            ->with(['agent:id,name,workspace_id', 'run:id,external_id,title,state'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Receipts island — last 25 receipts in either state. The chip is
     * rendered dashed amber `UNATTESTED` vs solid green linked
     * `ATTESTED` (per docs/ARCHITECTURE.md §1.2 honesty invariants).
     *
     * @return Collection<int, \App\Models\Receipt>
     */
    #[Computed]
    public function receiptsList(): Collection
    {
        $receiptAgentIds = $this->baseAgentQuery()->select('agents.id');

        return Receipt::query()
            ->whereIn('agent_id', $receiptAgentIds)
            ->with(['run:id,external_id,title', 'agent:id,name'])
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();
    }

    /**
     * Active agent-status values present in the visible (visibility-
     * filtered) agent set. The board "renders all 5 agent statuses" only
     * when the dataset actually contains them; we expose the enum list
     * itself so the template can iterate every status and label empty
     * cells consistently.
     *
     * @return list<AgentStatus>
     */
    #[Computed]
    public function statusList(): array
    {
        return [
            AgentStatus::Idle,
            AgentStatus::Working,
            AgentStatus::Blocked,
            AgentStatus::Done,
            AgentStatus::Offline,
        ];
    }

    /**
     * The current count of agents per status, visibility-filtered.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = $this->baseAgentQuery()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $out = [];
        foreach ($this->statusList as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * Helper: workspaces visible to this board instance. Public mode
     * hard-filters to public; authed mode is unfiltered.
     *
     * @return Builder<Workspace>
     */
    protected function baseWorkspaceQuery(): Builder
    {
        $q = Workspace::query();

        if ($this->mode === 'public') {
            $q->public();
        }

        return $q;
    }

    /**
     * Helper: agents in visible workspaces, joined through the visible
     * workspace set so we never accidentally surface a private agent
     * on the public board.
     *
     * @return Builder<Agent>
     */
    protected function baseAgentQuery(): Builder
    {
        return Agent::query()->whereIn('workspace_id', $this->baseWorkspaceQuery()->select('id'));
    }

    /**
     * Helper: drift flags whose agent is in a visible workspace.
     *
     * @return Builder<DriftFlag>
     */
    protected function baseDriftQuery(): Builder
    {
        return DriftFlag::query()->whereIn('agent_id', $this->baseAgentQuery()->select('agents.id'));
    }
    public function render(): View
    {
        return view('livewire.fleet-board', [
            'mode' => $this->mode,
            'isPublic' => $this->mode === 'public',
        ]);
    }
}
