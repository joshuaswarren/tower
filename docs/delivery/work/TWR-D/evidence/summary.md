# TWR-D evidence — Demo agents (lane D)

> WIP checkpoint (2026-07-11). This is the ~8-minute checkpoint, not the
> final delivery. Code, routes, and binding are in place; tests + delivery
> commit follow in this same turn.

## SDK choice (parent interjection)

The original task spec named `prism-php/prism` as the AI dependency. The
parent agent interrupted with a directive to verify the current
Laravel-supported AI package before adding the dependency. Verified via:

- context7 resolve-library-id for "Laravel AI" (one authoritative match,
  Source Reputation: High).
- Web search corroborated by:
  - https://laravel.com/docs/13.x/ai-sdk (Laravel 13.x AI SDK docs)
  - https://laravel.com/docs/12.x/ai-sdk (Laravel 12.x AI SDK docs)
  - https://laravel.com/blog/introducing-the-laravel-ai-sdk
  - https://github.com/laravel/ai (0.x branch source)
- Source review of the package on the 0.x branch:
  - `src/Contracts/Agent.php` (the streaming contract)
  - `src/Promptable.php` (the `stream()` method, returning
    `Laravel\Ai\Responses\StreamableAgentResponse`)
  - `src/Responses/StreamableAgentResponse.php` (IteratorAggregate
    yielding `Laravel\Ai\Streaming\Events\TextDelta`)
  - `src/Streaming/Events/TextDelta.php` (has a `->delta` string
    property and a `combine()` static helper)
  - `src/Gateway/FakeTextGateway.php` (fake splits text on spaces,
    one `TextDelta` per word)

Decision: use **`laravel/ai` v0.9.0** (the official first-party Laravel
AI SDK). The package wraps `prism-php/prism` internally as a transport
(the dependency shows up in `composer show laravel/ai`'s requires
section), so the parent-stated "wraps Prism internally" detail is
correct. The architecture's "Laravel AI SDK" naming matches this
package verbatim.

## Pin (parent interjection #2)

`composer.json` requires `laravel/ai` as the exact string `"0.9.0"`
(no `^`, no `~`, no dev/rc). Confirmed install with
`composer require "laravel/ai:0.9.0"` and the repo's
`minimum-stability=stable` was left untouched. License: MIT
(see https://spdx.org/licenses/MIT.html; the package's composer
manifest declares "MIT License (MIT) (OSI approved)").

If a future deploy needed a newer version, the change is a single
edit to `composer.json` + a re-run of the streaming contract test
(see below) — explicit, auditable, not silent.

## Streaming contract (parent interjection #3)

The `DemoNarrator` interface is stable. The wire format the SDK
produces (a generator of `Laravel\Ai\Streaming\Events\TextDelta`
events, each with a `->delta` string property) is the contract
`LaravelAiNarrator` consumes. A dedicated
`StreamingContractTest` asserts:

- `LaravelAiNarrator` calls the SDK's `stream($prompt)`.
- For each `TextDelta` yielded, the user callback receives the
  `->delta` value.
- The reassembled text equals the input string the fake gateway
  emitted (round-trip property).

If the SDK bumps `TextDelta` to a different shape, the contract
test goes red. The interface (`App\Support\Demo\DemoNarrator`)
does not change.

## Files in this checkpoint

```
app/Support/Demo/DemoNarrator.php          # interface (stable contract)
app/Support/Demo/LaravelAiNarrator.php     # real implementation
app/Support/Demo/FakeNarrator.php          # offline-only fake
app/Agents/FleetAnalyst.php                # real 24h board-data query
app/Events/Board/DemoOutputStreamed.php    # broadcastAs demo.chunk, NOT queued
app/Jobs/RunDemoAgent.php                  # queue=demo, streams + attests
app/Livewire/DemoConsole.php               # public dispatch UI
app/Providers/AppServiceProvider.php       # DemoNarrator binding
resources/views/livewire/demo-console.blade.php
routes/web.php                             # /demo gated by feature flag
composer.json                              # laravel/ai 0.9.0 (exact)
```

## What's next (in this turn)

- Pest tests under `tests/Feature/Demo/`:
  - `StreamingContractTest` (Laravel\Ai SDK reassembly)
  - `NarratorBindingTest` (Prism vs Fake by config)
  - `RunDemoAgentTest` (queued->running->done, >=2 chunks, attested
    receipt with report)
  - `RateLimitTest` (4th dispatch from one IP in 60s is rejected)
  - `ConcurrencyCapTest` (max_concurrent running → 5th dispatch rejected)
  - `FeatureFlagTest` (`tower.demo.enabled=false` → /demo 404s)
- Final delivery commit + receipt + per-test pest line receipts.
