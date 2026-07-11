# Tower

Self-hosted mission control for a personal coding-agent fleet. Agents, lanes, crons, and herdr-supervised panes POST heartbeats, state transitions, and receipts to a token-authed ingest API; a Reverb-powered live board shows what's running, what's blocked, what shipped (with receipt links), and what drifted outside its declared allowlist.

Status: architecture phase. Build starts with `TWR-001` in `docs/delivery/PLAN.md`.

## Documents

- `docs/ARCHITECTURE.md` — system design: domain model, ingest API contract, event flow, receipts/attestation, allowlist drift, herdr bridge plugin, demo agents, risks.
- `docs/REQUIREMENTS.md` — functional and non-functional requirements, tagged MUST / SHOULD / WONT.
- `docs/delivery/PLAN.md` — EDD work packets, dependency graph, schedule, Definition of Shippable, submission checklist.
- `docs/delivery/EDD-REFERENCE.md` — Evidence-Driven Delivery methodology reference.

## Stack (fixed)

Laravel 13 · Livewire 4 (Islands) · Reverb · Pest · Laravel Cloud (Serverless Postgres, Valkey, managed queues, scheduler). Single-tenant, open source, forkable. No SaaS plumbing.

## Design posture

- Metadata-only telemetry by default; PTY tail capture is opt-in per workspace with local redaction before any outbound POST.
- No reverse channel (board never controls agents) in this iteration.
- Every `done` run leaves a receipt stub; receipts are `unattested` until an authorized source attaches the artifact link.
- Token minting is CLI-only; no self-registration.
