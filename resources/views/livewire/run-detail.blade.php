<div class="space-y-6">
    <header class="flex items-baseline justify-between">
        <div>
            <p class="text-xs text-slate-400">Run</p>
            <h1 class="text-2xl font-semibold text-slate-100">
                {{ $this->run->title ?? $this->run->external_id }}
            </h1>
            <p class="text-xs text-slate-500">
                {{ $this->run->agent?->workspace?->name }} &middot;
                agent: {{ $this->run->agent?->name }} &middot;
                state: <span data-run-state>{{ $this->run->state->value ?? $this->run->state }}</span>
                @if ($this->isTerminal)
                    <span class="ml-2 text-emerald-300">[terminal]</span>
                @endif
            </p>
        </div>
        <a href="{{ route('board') }}" class="text-sm text-slate-300 hover:text-white">
            &larr; back to board
        </a>
    </header>

    {{-- timeline island (eager) --}}
    @island(name: 'timeline')
    <section
        data-island="timeline"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Run timeline"
    >
        <h2 class="mb-3 text-lg font-semibold">Timeline</h2>
        @if ($this->timeline->isEmpty())
            <p class="text-sm text-slate-400">No events for this run yet.</p>
        @else
            <ol class="space-y-2 text-sm" data-timeline>
                @foreach ($this->timeline as $event)
                    <li data-event-id="{{ $event->id }}" class="flex items-baseline gap-2 font-mono text-xs">
                        <span class="w-20 shrink-0 text-slate-500">
                            {{ $event->received_at?->format('H:i:s.u') ?? '--:--:--' }}
                        </span>
                        <span class="text-slate-300">{{ $event->type->value ?? $event->type }}</span>
                        @if ($event->from_state && $event->to_state)
                            <span class="text-slate-400">
                                {{ $event->from_state }} <span class="text-slate-600">-&gt;</span> {{ $event->to_state }}
                            </span>
                        @endif
                        @if (! empty($event->payload))
                            <span class="truncate text-slate-500">
                                {{ is_array($event->payload) ? json_encode($event->payload, JSON_UNESCAPED_SLASHES) : (string) $event->payload }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
    @endisland

    {{-- receipts island (eager) --}}
    @island(name: 'receipts')
    <section
        data-island="receipts"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Receipts"
    >
        <h2 class="mb-3 text-lg font-semibold">Receipts</h2>
        @if ($this->receipts->isEmpty())
            <p class="text-sm text-slate-400">No receipts yet.</p>
        @else
            <ul class="space-y-2" data-receipts>
                @foreach ($this->receipts as $receipt)
                    <li
                        data-receipt-id="{{ $receipt->id }}"
                        data-receipt-status="{{ $receipt->status->value ?? $receipt->status }}"
                        class="flex items-center justify-between gap-3 rounded border border-slate-800 bg-slate-950/40 p-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-200">
                                {{ $receipt->summary ?? ($receipt->kind ?? 'receipt') }}
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                stub: {{ is_array($receipt->stub) ? implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($receipt->stub), array_values($receipt->stub))) : '' }}
                            </p>
                        </div>
                        @if ($receipt->status->value === \App\Enums\ReceiptStatus::Attested->value)
                            <a
                                href="{{ $receipt->url }}"
                                target="_blank"
                                rel="noopener"
                                class="inline-block rounded border-2 border-emerald-500 bg-emerald-600 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-500"
                            >ATTESTED</a>
                        @else
                            <span
                                class="inline-block rounded border-2 border-dashed border-amber-500 bg-transparent px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-amber-300"
                            >UNATTESTED</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endisland

    {{-- drift flags island (eager, with admin actions) --}}
    @island(name: 'drift')
    <section
        data-island="drift"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Drift flags"
    >
        <h2 class="mb-3 text-lg font-semibold">Drift flags</h2>
        @if ($this->driftFlags->isEmpty())
            <p class="text-sm text-slate-400">No open or acknowledged drift flags on this run.</p>
        @else
            <ul class="space-y-2" data-drift>
                @foreach ($this->driftFlags as $flag)
                    <li
                        data-drift-id="{{ $flag->id }}"
                        data-severity="{{ $flag->severity->value ?? $flag->severity }}"
                        data-status="{{ $flag->status->value ?? $flag->status }}"
                        class="flex items-center justify-between gap-3 rounded border border-slate-800 bg-slate-950/40 p-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-200">
                                <span class="mr-1 inline-block rounded bg-slate-800 px-1 text-xs uppercase tracking-wide text-slate-200">
                                    {{ $flag->severity->value ?? $flag->severity }}
                                </span>
                                tool: {{ $flag->tool }}
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                status: {{ $flag->status->value ?? $flag->status }} &middot;
                                count: {{ is_array($flag->detail) ? ($flag->detail['count'] ?? 1) : 1 }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            @if (($flag->status->value ?? $flag->status) === \App\Enums\DriftStatus::Open->value)
                                <button
                                    type="button"
                                    wire:click="acknowledgeDrift('{{ $flag->id }}')"
                                    class="rounded border border-slate-700 bg-slate-900 px-2 py-1 text-xs text-slate-200 hover:bg-slate-800"
                                >acknowledge</button>
                            @endif
                            <button
                                type="button"
                                wire:click="resolveDrift('{{ $flag->id }}')"
                                class="rounded border border-emerald-700 bg-emerald-900/40 px-2 py-1 text-xs text-emerald-100 hover:bg-emerald-800"
                            >resolve</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endisland
</div>
