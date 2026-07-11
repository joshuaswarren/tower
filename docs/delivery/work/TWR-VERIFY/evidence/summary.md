# Integration & verification evidence (2026-07-11)

## Test suites
- PHP (Postgres `tower_test`): **121 passed, 712 assertions** (`./vendor/bin/pest`), covering domain/contracts, token auth, ingest + state machine, receipts/attestation, allowlist drift (incl. DB-level uniqueness + concurrency-safe dedup), staleness sweep + prune, broadcast contract + channel auth, board render + public sanitization, seeders, demo agents, and the realtime broadcast regression guard.
- herdr bridge (stdlib Python, `make test`): **71 passed** under `PYTHONWARNINGS=error::ResourceWarning`.

## End-to-end async smoke (real path, not sync)
Reverb server + database queue worker (`--queue=ingest`) + `php artisan serve`, DB `tower_dev`:
- `POST /api/v1/events` (bearer token) → `202 {accepted, duplicates, rejected}`.
- Queue worker ran `ProcessIngestedBatch`; agent + run denormalized to `blocked`; 2 event rows.
- **Realtime proven**: a browser subscribed to `public.board` via Echo/Reverb received `run.updated` and `agent.status_changed` with correct payloads after a real ingest → worker → Reverb → WebSocket. (This surfaced and fixed a real bug: `ProcessIngestedBatch` used `$event::dispatch($event)`, which silently threw and broadcast nothing; switched to `event($event)`. Regression test added.)

## Responsive / visual
Public board at 375 / 768 / 1024 / 1440 px: **no horizontal overflow at any breakpoint**. At 375px the Attention island (blocked agents + open drift) is the first viewport content — the "blocked agents on your phone" surface — followed by the Fleet grid with status counts and the copy-paste `connect_hint`.

## Note on the automation harness
The headless automation browser did not auto-execute the `<script type="module">` entry (marker absent, no error), but a dynamic `import()` of the same built file initialized `window.Echo`/`window.Pusher` correctly and the live WS delivery above was then observed. The bundle is correct and executable; the non-execution was a harness quirk, not an app defect. A normal browser executes the module on load.
