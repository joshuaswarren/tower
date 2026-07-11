# TWR-D evidence — demo agents (feature-flagged, real Laravel AI SDK)

## Result
`DB_DATABASE=tower_test_d ./vendor/bin/pest tests/Feature/Demo` — **23 passed, 245 assertions**.

## AI SDK verification (per the pre-1.0 / provenance directive)
- Package: **`laravel/ai` v0.9.0**, first-party Laravel AI SDK (MIT). Pinned EXACTLY (`"laravel/ai": "0.9.0"`) in composer.json — no caret, repo stays `minimum-stability: stable`.
- Streaming API confirmed against the INSTALLED source (not just docs): `Laravel\Ai\AnonymousAgent`->`stream($prompt)` returns `Laravel\Ai\Responses\StreamableAgentResponse` (iterable) yielding `Laravel\Ai\Streaming\Events\TextDelta` chunks with a public `->delta` string; `TextDelta::combine()` reassembles. (LaneD's initial doc-based guess of a `Laravel\Ai\Facades\Ai` facade was WRONG — no such facade exists in 0.9.0; the real entry is `AnonymousAgent`. Corrected.)

## Design
- `DemoNarrator` interface (`stream(string $prompt, callable $onChunk): string`) is the stable seam.
- `LaravelAiNarrator` = REAL path: constructs `AnonymousAgent`, consumes `TextDelta` deltas, reassembles. Bound when an AI provider key is configured.
- `FakeNarrator` = deterministic, test/offline ONLY (documented — not a substitute for the real path).
- `FleetAnalyst` builds the prompt from a REAL 24h board query (busiest agent, blocked time, drift summary).
- `RunDemoAgent` (queue `demo`) dogfoods ingest: creates a kind=demo agent + ingest/attest token in the public demo workspace, drives queued→running→done, streams `DemoOutputStreamed` (`demo.chunk` on `demo.run.{id}`, inline/not-queued), and ends with a real ATTESTED receipt (report as artifact).
- All gated by `config('tower.demo.enabled')` (default false). `/demo` route always registered (name resolves); `DemoConsole` redirects to login when disabled (same UX as the disabled public board). Cut-safe: delete `app/Agents/*` + `DemoConsole` + the route line + the AppServiceProvider bind.

## Proven
Feature-flag on/off (route + console), narrator binding (Prism-real vs Fake by config), streaming contract (TextDelta reassembly round-trip + `combine()`), run lifecycle queued→running→done on the board, ≥2 DemoOutputStreamed chunks on the right channel, attested receipt with report, rate-limit (per-IP) + concurrency cap enforcement.

## Bugs fixed during salvage (orchestrator)
- `RunDemoAgent` persisted `$narrator::class` for an anonymous narrator — the NUL byte is invalid in Postgres jsonb; now stores `'anonymous'`.
- Tests: duplicate `Event` import alias; `Event::dispatched()` returns `[event, payload]` tuples (destructured); contract test referenced the non-existent `Facades\Ai` (rewritten to inject a fake agent through the narrator's `agentFactory`).
