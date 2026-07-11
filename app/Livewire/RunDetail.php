<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\DriftStatus;
use App\Enums\RunState;
use App\Models\DriftFlag;
use App\Models\Receipt;
use App\Models\Run;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Per-run admin view. The contract (docs/ARCHITECTURE.md §1.4, TWR-022):
 *
 * - full event timeline, in order, oldest first
 * - receipt chip: dashed amber UNATTESTED vs solid green linked ATTESTED
 * - drift flag list with acknowledge/resolve actions (admin only — the
 *   auth middleware is already on the route, so we can act with confidence)
 *
 * The run is the one in the route; render() throws a 404 if missing
 * (Livewire's missing() convention is fine for 404, but route model
 * binding would also work — we accept the run id and look it up
 * explicitly so the test surface is obvious).
 */
#[Layout('components.layouts.app')]
#[Title('Run')]
final class RunDetail extends Component
{
    public string $runId = '';

    public function mount(string $run = ''): void
    {
        $this->runId = $run;
    }

    /**
     * The run with its relationships. 404s if missing.
     */
    #[Computed]
    public function run(): Run
    {
        $run = Run::query()
            ->with(['agent:id,name,workspace_id', 'agent.workspace:id,name,visibility'])
            ->find($this->runId);

        abort_if($run === null, 404);

        return $run;
    }

    /**
     * The event timeline, oldest first. Includes the canonical
     * `run.state_changed` events that drove the run to its current
     * state, plus any `tool.invoked` / `log.note` rows for context.
     */
    #[Computed]
    public function timeline(): EloquentCollection
    {
        return $this->run->events()
            ->orderBy('id')   // bigint auto-increment = chronological
            ->get();
    }

    /**
     * Receipts attached to this run. There is at most one stub per done
     * transition; we don't constrain to one here — the contract is that
     * attestation is immutable, so multiple receipts on a run represent
     * multiple done attempts.
     */
    #[Computed]
    public function receipts(): EloquentCollection
    {
        return $this->run->receipts()
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Open or acknowledged drift flags for this run. Resolved ones
     * disappear from the active list.
     */
    #[Computed]
    public function driftFlags(): EloquentCollection
    {
        return DriftFlag::query()
            ->where('run_id', $this->runId)
            ->whereIn('status', [DriftStatus::Open->value, DriftStatus::Acknowledged->value])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Admin action: mark a drift flag as acknowledged. The action is
     * idempotent — acknowledging an already-acknowledged flag is a
     * no-op (we just don't error).
     */
    public function acknowledgeDrift(string $flagId): void
    {
        $flag = DriftFlag::query()->findOrFail($flagId);
        abort_unless($flag->run_id === $this->runId, 404);

        if ($flag->status === DriftStatus::Open) {
            $flag->status = DriftStatus::Acknowledged;
            $flag->save();
        }

        $this->dispatch('drift.ack');
    }

    /**
     * Admin action: mark a drift flag as resolved. Resolved flags drop
     * off the active list (see `driftFlags()`).
     */
    public function resolveDrift(string $flagId): void
    {
        $flag = DriftFlag::query()->findOrFail($flagId);
        abort_unless($flag->run_id === $this->runId, 404);

        $flag->status = DriftStatus::Resolved;
        $flag->save();

        $this->dispatch('drift.resolve');
    }

    /**
     * Terminal-state truth: which run states are terminal. Surfaced as
     * a method so the template can render a clear "this run is done"
     * banner without hard-coding the enum list.
     */
    #[Computed]
    public function isTerminal(): bool
    {
        return $this->run->state instanceof RunState
            ? $this->run->state->isTerminal()
            : RunState::tryFrom((string) $this->run->state)?->isTerminal() ?? false;
    }

    public function render(): View
    {
        return view('livewire.run-detail', [
            'user' => Auth::user(),
        ]);
    }
}
