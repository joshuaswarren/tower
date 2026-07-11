<div
    data-demo-console
    data-channel="{{ $this->activeRunId ? 'demo.run.'.$this->activeRunId : '' }}"
    class="mx-auto max-w-3xl space-y-5 p-4 sm:p-6"
>
    <header>
        <h1 class="text-2xl font-semibold text-slate-100">Demo: FleetAnalyst</h1>
        <p class="mt-1 text-sm text-slate-400">
            Dispatch a demo agent that reads the last 24 hours of board data and streams a
            fleet-health briefing. Each demo run produces a real attested receipt — the
            honesty loop, on stage.
        </p>
    </header>

    @if ($this->errorMessage)
        <div
            role="alert"
            class="rounded border border-rose-700 bg-rose-950/40 px-3 py-2 text-sm text-rose-100"
        >
            {{ $this->errorMessage }}
        </div>
    @endif

    @if ($this->infoMessage && ! $this->errorMessage)
        <div
            role="status"
            class="rounded border border-emerald-700 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-100"
        >
            {{ $this->infoMessage }}
        </div>
    @endif

    <section
        class="rounded-lg border border-slate-800 bg-slate-900/60 p-4"
        aria-label="Dispatch"
    >
        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="dispatchFleetAnalyst"
                @disabled($this->dispatchDisabled)
                class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Dispatch FleetAnalyst
            </button>
            @if ($this->activeRunId)
                <button
                    type="button"
                    wire:click="clearStream"
                    class="rounded border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800"
                >
                    Clear
                </button>
            @endif
            <span class="text-xs text-slate-400">
                cap: {{ (int) config('tower.demo.max_concurrent', 3) }} concurrent ·
                {{ (int) config('tower.demo.rate_per_minute_per_ip', 3) }}/min per IP
            </span>
        </div>
    </section>

    <section
        class="rounded-lg border border-slate-800 bg-slate-950/60 p-4"
        aria-label="Live stream"
        wire:poll.1s
    >
        <header class="mb-2 flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-slate-200">Live stream</h2>
            @if ($this->activeRunId)
                <span class="text-xs text-slate-500">
                    run {{ $this->activeRunId }} · channel demo.run.{{ $this->activeRunId }}
                </span>
            @endif
        </header>
        <textarea
            readonly
            rows="14"
            class="w-full resize-y rounded border border-slate-800 bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100 focus:outline-none"
            @if ($this->activeRunId)
                wire:model.live="streamedText"
            @endif
            placeholder="{{ $this->activeRunId ? 'streaming…' : 'No active run. Click Dispatch.' }}"
        >{{ $this->streamedText }}</textarea>
    </section>
</div>
