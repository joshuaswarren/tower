<div
    data-board
    data-mode="<?php echo e($this->mode); ?>"
    data-channel="<?php echo e($this->broadcastChannel); ?>"
    class="space-y-6"
>
    @if ($this->isPublic)
        <p class="rounded border border-slate-700 bg-slate-900/60 px-3 py-2 text-xs text-slate-400">
            Public board &mdash; only public workspaces are shown. This view never renders private agent
            names, run titles, or receipt URLs.
        </p>
    @endif

    {{-- attention island: blocked agents + open drift, phone-first, eager --}}
    @island(name: 'attention')
    <section
        data-island="attention"
        wire:poll.30s
        class="rounded-lg border border-amber-800/60 bg-amber-950/30 p-4 sm:p-5"
        aria-label="Attention"
    >
        <header class="mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold text-amber-100">Attention</h2>
            <span class="text-xs text-amber-300/80">
                {{ $this->attentionItems->count() }} item{{ $this->attentionItems->count() === 1 ? '' : 's' }}
            </span>
        </header>

        @if ($this->attentionItems->isEmpty())
            <p class="text-sm text-amber-200/70">All clear &mdash; no blocked agents, no open drift.</p>
        @else
            <ul class="space-y-2">
                @foreach ($this->attentionItems as $item)
                    <li
                        class="flex items-start justify-between gap-3 rounded border border-amber-900/60 bg-amber-950/40 p-2"
                        data-kind="{{ $item['kind'] }}"
                        data-id="{{ $item['id'] }}"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-amber-50">
                                @if ($item['kind'] === 'drift' && $item['severity'])
                                    <span class="mr-1 inline-block rounded bg-red-900/60 px-1 text-xs uppercase tracking-wide text-red-200">
                                        {{ $item['severity'] }}
                                    </span>
                                @endif
                                {{ $item['label'] }}
                            </p>
                            <p class="truncate text-xs text-amber-200/70">{{ $item['detail'] }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-amber-300/80">{{ $item['since'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endisland

    {{-- grid island: host -> workspace -> agent tree, eager --}}
    @island(name: 'grid')
    <section
        data-island="grid"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Fleet grid"
    >
        <header class="mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Fleet</h2>
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ($this->statusList as $status)
                    <span
                        class="rounded px-2 py-0.5 font-medium {{ match ($status->value) {
                            'working' => 'bg-sky-900/60 text-sky-200',
                            'blocked' => 'bg-amber-900/60 text-amber-200',
                            'done'    => 'bg-emerald-900/60 text-emerald-200',
                            'idle'    => 'bg-slate-700/60 text-slate-200',
                            'offline' => 'bg-slate-800/60 text-slate-400',
                            default   => 'bg-slate-700/60 text-slate-200',
                        } }}"
                    >
                        {{ $status->value }} ({{ $this->statusCounts[$status->value] ?? 0 }})
                    </span>
                @endforeach
            </div>
        </header>

        @if ($this->gridGroups->isEmpty())
            <p class="text-sm text-slate-400">No hosts to show{{ $this->isPublic ? ' (public workspaces only)' : '' }}.</p>
        @else
            <div class="space-y-4">
                @foreach ($this->gridGroups as $host)
                    <div data-host-id="{{ $host['name'] }}" class="rounded border border-slate-800/60 bg-slate-950/40 p-3">
                        <div class="mb-2 flex items-baseline justify-between">
                            <h3 class="text-base font-semibold text-slate-100">{{ $host['name'] }}</h3>
                            @if (! empty($host['connect_hint']))
                                <button
                                    type="button"
                                    class="rounded border border-slate-700 bg-slate-900 px-2 py-0.5 font-mono text-xs text-slate-200 hover:bg-slate-800"
                                    data-copy-text="{{ $host['connect_hint'] }}"
                                    onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copyText)"
                                >
                                    {{ $host['connect_hint'] }}
                                </button>
                            @endif
                        </div>

                        @foreach ($host['workspaces'] as $ws)
                            <div class="mb-2 ml-2 border-l border-slate-800 pl-3">
                                <p class="mb-1 text-sm font-medium text-slate-300">
                                    {{ $ws['name'] }}
                                    <span class="ml-2 text-xs text-slate-500">[{{ $ws['visibility'] }}]</span>
                                </p>
                                @if (empty($ws['agents']))
                                    <p class="text-xs text-slate-500">no agents</p>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($ws['agents'] as $agent)
                                            <li class="flex items-center gap-2 text-sm" data-agent-id="{{ $agent['id'] }}">
                                                <span
                                                    class="inline-block h-2 w-2 shrink-0 rounded-full {{ match ($agent['status']) {
                                                        'working' => 'bg-sky-400',
                                                        'blocked' => 'bg-amber-400',
                                                        'done'    => 'bg-emerald-400',
                                                        'idle'    => 'bg-slate-500',
                                                        'offline' => 'bg-slate-700',
                                                        default   => 'bg-slate-500',
                                                    } }}"
                                                ></span>
                                                <span class="font-mono text-xs text-slate-400">{{ $agent['kind'] }}</span>
                                                <span class="text-slate-100">{{ $agent['name'] }}</span>
                                                @if (! empty($agent['active_run_title']))
                                                    <span class="ml-2 truncate text-xs text-slate-400">
                                                        &middot; {{ $agent['active_run_title'] }}
                                                        @if (! empty($agent['active_run_state']))
                                                            <span class="ml-1 text-slate-500">[{{ $agent['active_run_state'] }}]</span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </section>
    @endisland

    {{-- feed island: recent events, lazy, append mode --}}
    @island(name: 'feed', lazy: true)
    <section
        data-island="feed"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Event feed"
    >
        <header class="mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Feed</h2>
            <span class="text-xs text-slate-400">{{ $this->feedEvents->count() }} events</span>
        </header>

        @if ($this->feedEvents->isEmpty())
            <p class="text-sm text-slate-400">No events yet.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($this->feedEvents as $event)
                    <li data-event-id="{{ $event->id }}" class="flex items-baseline gap-2 font-mono text-xs">
                        <span class="text-slate-500">{{ $event->received_at?->format('H:i:s') ?? '--:--:--' }}</span>
                        <span class="text-slate-300">{{ $event->type->value }}</span>
                        <span class="truncate text-slate-400">{{ $event->agent?->name }}</span>
                        @if ($event->from_state && $event->to_state)
                            <span class="text-slate-500">[{{ $event->from_state }} -> {{ $event->to_state }}]</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endisland

    {{-- receipts island: latest receipts, lazy --}}
    @island(name: 'receipts', lazy: true)
    <section
        data-island="receipts"
        wire:poll.30s
        class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 sm:p-5"
        aria-label="Receipts"
    >
        <header class="mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Receipts</h2>
            <span class="text-xs text-slate-400">{{ $this->receiptsList->count() }}</span>
        </header>

        @if ($this->receiptsList->isEmpty())
            <p class="text-sm text-slate-400">No receipts yet.</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($this->receiptsList as $receipt)
                    <li data-receipt-id="{{ $receipt->id }}" class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-slate-200">
                                {{ $receipt->run?->title ?? $receipt->run?->external_id ?? 'run' }}
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $receipt->agent?->name }}
                            </p>
                        </div>
                        @if ($receipt->status->value === \App\Enums\ReceiptStatus::Attested->value)
                            <a
                                href="{{ $receipt->url }}"
                                target="_blank"
                                rel="noopener"
                                data-receipt-status="attested"
                                class="inline-block rounded border-2 border-emerald-500 bg-emerald-600 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-500"
                            >ATTESTED</a>
                        @else
                            <span
                                data-receipt-status="unattested"
                                class="inline-block rounded border-2 border-dashed border-amber-500 bg-transparent px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-amber-300"
                            >UNATTESTED</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endisland
</div>
