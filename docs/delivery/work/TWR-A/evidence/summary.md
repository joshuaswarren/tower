# TWR-A evidence — backend (token auth, ingest, state machine, drift, receipts, scheduler)

## Result
`DB_DATABASE=tower_test_a ./vendor/bin/pest tests/Feature tests/Unit` — **69 passed, 373 assertions** (incl. spine's 23).

## Proven
- Token auth: valid `twr_` token → 200 ping with abilities; bad token 401; missing ability 403; over-rate 429. Mint CLI prints the plaintext once; only the SHA-256 hash persists.
- Ingest: batch with a duplicate `dedupe_key` → `{accepted, duplicates}` with the right row count; legal transitions advance state; illegal transition recorded-but-rejected (`payload.transition_rejected=true`, run unchanged, in `rejected[]`); oversize batch → 422; oversize payload → 413.
- State machine (`ApplyRunTransition`): legal advance sets `started_at`; terminal transition computes `duration_ms` and `exit_state`, sets `ended_at`.
- Receipts: `done` → one `unattested` stub; attest requires the `attest` ability + non-empty url/summary; immutable once attested (second attest 409).
- Drift (`DetectDrift`): manifest miss → one `warn`; `deny` match → `violation`; no manifest → no flags; repeats accumulate `detail.count` both within one batch AND across batches (deduped per agent+tool+run, run-less events keyed on NULL run_id); `tower.drift.enabled=false` → no-op.
- herdr ingest: envelope upserts host/workspace/pane-agents, maps state 1:1, snapshot reconciliation; tier-0 tail rejected.
- Scheduler: `tower:sweep-stale` flips stale agents to `offline`; `tower:prune-events` deletes only rows older than the cutoff.

## Bugs fixed during completion (in lane A code)
- `ApplyRunTransition`: `duration_ms` always computed 0 — it checked `$run->started_at instanceof CarbonImmutable`, but the model casts to mutable `Carbon`, so it fell back to `now()` and the diff went negative → clamped to 0. Fixed with `CarbonImmutable::instance()` normalization.
- `DetectDrift`: (1) iterated invocation VALUES instead of tool KEYS, passing an array into `classify(string)`; (2) wrote the `__none__` run sentinel into `drift_flags.run_id` → FK violation (now mapped to NULL with `whereNull` dedup); (3) same-tool repeats within a batch overwrote each other, losing the count — now accumulates `{count, first_event_id, last_event_id}` per (agent, run, tool).
- `ActionsTest`: first test was malformed (used `$run` before creation, wrong assertions) — rewrote as two correct behavioral tests.

## Drift uniqueness — enforced at the database layer
Two partial unique indexes on `drift_flags` (migration `..._add_drift_flags_unique_indexes.php`):
`(agent_id, tool, run_id) WHERE run_id IS NOT NULL` and `(agent_id, tool) WHERE run_id IS NULL`
(Postgres treats NULLs as distinct, so run-bound and run-less flags need separate partials).
`DetectDrift` bumps an existing flag under a `lockForUpdate` row lock (serializes concurrent
count bumps) and catches a unique-violation on the insert race, folding it into a bump — so the
"at most one flag per (agent, tool, run)" invariant holds even under concurrent queue workers
(not relying on any serial-worker assumption). Same-batch and cross-batch repeat accumulation
are both tested.
