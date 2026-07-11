# Tower — Founding Architecture, Requirements & EDD Project Plan

**Repo:** `joshuaswarren/tower` (new) · **Date:** 2026-07-11 · **Window:** Sat 2026-07-11 08:00 → Sun 2026-07-12 submission
**Stack (fixed):** Laravel 13, Livewire 4 (Islands), Reverb, Pest, Laravel Cloud (Serverless Postgres + pgvector, Valkey, managed queues, scheduler). Single-tenant, open-source, no SaaS plumbing.
**Methodology:** Evidence-Driven Delivery per `local://edd-digest.md`. This document is the architect's technical doc — README/marketing prose is out of scope (voice-guard pipeline handles it separately).

---

> Founding document set, split from the 2026-07-11 architecture session. Cross-references: docs/ARCHITECTURE.md, docs/REQUIREMENTS.md, docs/delivery/PLAN.md.

# 2. REQUIREMENTS

Tags: **MUST** = ships Sunday, submission-gating. **SHOULD** = built if on pace, cut-safe post-noon-Sunday. **WONT** = explicitly out of scope this weekend.

### Functional
| # | Requirement | Tag |
|---|---|---|
| FR-1 | Token-authed `POST /api/v1/events` accepts the `tower.ingest.v1` batch envelope with partial acceptance (`accepted/duplicates/rejected`) and idempotency via `dedupe_key`. | MUST |
| FR-2 | `POST /api/v1/heartbeat` updates agent liveness; agents with stale heartbeats are swept to `offline` within `tower.staleness.offline_seconds` + 60s. | MUST |
| FR-3 | CLI token minting (`tower:agent:create`, `tower:host:create`) with abilities; plaintext shown once; SHA-256 at rest. No self-registration endpoint. | MUST |
| FR-4 | Run state machine (`queued/running/blocked/done/failed/abandoned`) with illegal transitions recorded-but-rejected. | MUST |
| FR-5 | Every `done` transition auto-creates an `unattested` receipt stub (agent, workspace, duration, exit state). | MUST |
| FR-6 | Receipt attestation (URL/summary attach) only via admin session or `attest`-ability token; `unattested→attested` one-way; board renders the two states unmistakably. | MUST |
| FR-7 | Live board `/board` (authed) shows host → workspace → agent topology, agent status, active runs, recent events, receipts — updating via Reverb-triggered island refresh with `wire:poll.30s` fallback. | MUST |
| FR-8 | "Needs attention" surface: blocked agents + open drift flags, first on the page, usable on a phone (375px). | MUST |
| FR-9 | Public read-only board `/` showing only `public`-visibility workspaces, with slimmed broadcast payloads on `public.board`. | MUST |
| FR-10 | Allowlist declaration endpoint (versioned manifests) + drift detection (glob match of invoked tools/scopes) raising deduped `drift_flags` broadcast to the board. | MUST |
| FR-11 | Drift flags acknowledgeable/resolvable from the admin board. | SHOULD |
| FR-12 | herdr bridge plugin (tier 0): snapshot bootstrap + `events.subscribe`, batched POSTs, retry + offline spool, installable via `herdr plugin install joshuaswarren/tower/herdr-plugin`. | MUST |
| FR-13 | herdr topology renders natively (host → workspace → pane-agent; 4 states map 1:1); attach affordance shows copyable `connect_hint` only. | MUST |
| FR-14 | herdr tier 1 opt-in tail capture with local redaction (built-in secret patterns + env values + user deny-regexes) and a server-side redaction backstop that rejects secret-looking tails. | SHOULD |
| FR-15 | Joshua's real fleet wired: omp lane POST hook, OpenClaw heartbeat hook, herdr bridge live on claude-a and codex-a — real multi-box data on the board for judging. | MUST |
| FR-16 | Demo workspace seeder producing a coherent, clearly-DEMO-labeled sanitized board for forks and the public instance. | MUST |
| FR-17 | Visitor-dispatchable demo agents (≥1, AI SDK `stream()` → `demo.run.{id}` channel) that dogfood the ingest path and leave real receipts; rate-limited and globally capped. | SHOULD |
| FR-18 | Event retention pruning (`tower:prune-events`, 30-day default) on the Cloud scheduler. | SHOULD |
| FR-19 | Admin login (single env-seeded user, registration disabled). | MUST |

### Non-functional
| # | Requirement | Tag |
|---|---|---|
| NFR-1 | Deployed and serving at a `*.laravel.cloud` URL from Saturday morning onward; every merge to `main` auto-deploys. | MUST |
| NFR-2 | Event→board-pixel latency p95 < 2s with Reverb up (measured over ≥20 events, evidence captured). | MUST |
| NFR-3 | Ingest write path: single transaction, no external calls, no LLM calls; p95 < 300ms at batch size 25. | MUST |
| NFR-4 | No secrets in repo; `.env.example` documents names only; tier-1 redaction before any PTY content leaves a host. | MUST |
| NFR-5 | Pest suite: every ingest/state-machine/receipt/drift/sanitization behavior covered; deterministic; runs in CI as a hard gate. | MUST |
| NFR-6 | no-fakes-no-stubs: shipped paths contain no mocks/TODO-implements; cut features are absent, not stubbed. Enforced by deterministic grep gate in CI. | MUST |
| NFR-7 | Clone-to-deploy forkability: fresh clone + env vars + Cloud resources = working instance, no manual DB surgery (seeder covers bootstrap). | MUST |
| NFR-8 | EDD in force from commit one: packets, working logs, evidence dirs, ADRs, merge gate. | MUST |
| NFR-9 | Rate limiting + payload caps on all ingest routes (Valkey-backed). | MUST |
| NFR-10 | Nightwatch enabled on production before judging. | SHOULD |
| NFR-11 | Board fully usable 375/768/1024/1440px per frontend visual standards. | SHOULD |

### Out of scope this weekend
| # | Explicitly not building | 
|---|---|
| WONT-1 | Reverse channel (send_keys / approve-from-board / any board→agent control path). Post-weekend, needs its own allowlist + local confirmation design. |
| WONT-2 | Multi-tenancy, billing, email verification, team/user management, SSO — any SaaS plumbing. |
| WONT-3 | Live PTY streaming or any terminal content beyond opt-in tier-1 done-transition tails. |
| WONT-4 | pgvector-backed semantic search/embeddings over events (provisioned, unused). |
| WONT-5 | Historical analytics/reporting beyond the 30-day event feed. |
| WONT-6 | Native mobile app (responsive web only). |
| WONT-7 | Non-herdr bridge daemons (omp/OpenClaw integrate via plain POST hooks, not shipped daemons). |

---
