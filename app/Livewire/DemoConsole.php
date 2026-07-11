<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Agents\FleetAnalyst;
use App\Enums\RunState;
use App\Jobs\RunDemoAgent;
use App\Models\Run;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * DemoConsole — public demo dispatch UI (docs/ARCHITECTURE.md §1.7).
 *
 * Only mounted when `tower.demo.enabled` is true. Cutsafe: deleting
 * this class + its route + the bind in AppServiceProvider fully
 * removes the demo surface (per §1.7).
 *
 * The console:
 *   - Renders a dispatch button + a live stream area.
 *   - Enforces `tower.demo.rate_per_minute_per_ip` (per IP) on dispatch.
 *   - Enforces `tower.demo.max_concurrent` (global cap on running
 *     demo runs) on dispatch.
 *   - Subscribes to `demo.run.{id}` via the JS bridge (board.js) so
 *     streamed chunks append to the visible stream area.
 */
#[Layout('components.layouts.app')]
#[Title('Demo')]
final class DemoConsole extends Component
{
    public ?string $activeRunId = null;

    public string $streamedText = '';

    public ?string $errorMessage = null;

    public ?string $infoMessage = null;

    public function mount(): void
    {
        if (! (bool) config('tower.demo.enabled', false)) {
            $this->redirectRoute('login');
            return;
        }
    }

    /**
     * Dispatch a FleetAnalyst demo run. Returns void; success / failure
     * is reflected through the rendered `$errorMessage` / `$infoMessage`
     * and the active `$activeRunId` for the JS bridge to subscribe to.
     */
    public function dispatchFleetAnalyst(): void
    {
        if (! (bool) config('tower.demo.enabled', false)) {
            $this->errorMessage = 'Demo is disabled.';
            return;
        }

        // Per-IP rate limit.
        $perMinute = (int) config('tower.demo.rate_per_minute_per_ip', 3);
        $key = 'demo-dispatch:'.(string) request()->ip();
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $retry = RateLimiter::availableIn($key);
            $this->errorMessage = "Rate limit: try again in {$retry} second(s).";
            $this->infoMessage = null;
            return;
        }
        RateLimiter::hit($key, 60);

        // Global concurrent cap.
        $max = (int) config('tower.demo.max_concurrent', 3);
        $running = Run::query()
            ->where('state', RunState::Running)
            ->whereHas('agent', fn ($q) => $q->where('kind', 'demo'))
            ->count();
        if ($running >= $max) {
            $this->errorMessage = "Concurrency cap: {$max} demo run(s) already in progress. Try again shortly.";
            $this->infoMessage = null;
            return;
        }

        $externalId = FleetAnalyst::newExternalId();

        // The job is dispatched on the `demo` queue. In tests with
        // QUEUE_CONNECTION=sync the handle() runs inline, which is what
        // the acceptance test relies on.
        RunDemoAgent::dispatch($externalId);

        // The new run id is computed from the external id via a small
        // lookup-or-fail. With sync queues the job has already run by
        // the time we get here, so the run row exists.
        $run = Run::query()->where('external_id', $externalId)->first();
        $this->activeRunId = $run?->id;
        $this->streamedText = '';
        $this->errorMessage = null;
        $this->infoMessage = $run !== null
            ? 'Demo run dispatched. Streaming now…'
            : 'Demo run dispatched.';
    }

    /**
     * Reset the visible stream and clear the active run.
     */
    public function clearStream(): void
    {
        $this->activeRunId = null;
        $this->streamedText = '';
        $this->infoMessage = null;
        $this->errorMessage = null;
    }

    /**
     * A Livewire event handler the JS bridge may call when a
     * `demo.chunk` arrives, so the stream area updates without a
     * round-trip. Optional — the `wire:poll.1s` fallback on the
     * rendered stream textarea also works.
     */
    public function appendChunk(string $chunk): void
    {
        $this->streamedText .= $chunk;
    }

    /**
     * Whether the dispatch button is disabled (concurrency cap hit
     * or demo feature-flagged off). Surfaced as a computed so the
     * view can read it without a round-trip.
     */
    #[Computed]
    public function dispatchDisabled(): bool
    {
        if (! (bool) config('tower.demo.enabled', false)) {
            return true;
        }
        $max = (int) config('tower.demo.max_concurrent', 3);
        $running = Run::query()
            ->where('state', RunState::Running)
            ->whereHas('agent', fn ($q) => $q->where('kind', 'demo'))
            ->count();
        return $running >= $max;
    }

    public function render(): View
    {
        return view('livewire.demo-console');
    }
}
