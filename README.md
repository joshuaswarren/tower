# Tower

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-pink)](https://github.com/sponsors/joshuaswarren)

Self-hosted mission control for a personal coding-agent fleet. Agents, lanes, crons, CI jobs, and herdr-supervised panes POST heartbeats, run-state transitions, and receipts to a token-authed ingest API; a Reverb-powered Livewire board shows the whole fleet live — what's running, what's blocked, what shipped (with receipt links), and what drifted outside its declared allowlist.

Single-tenant, open source, forkable. Built with Laravel 13, Livewire 4 (Islands), Reverb, Pest, on Laravel Cloud.

## Why

Stale `HEARTBEAT.md` files and `boot-last-report.json` age-checks lie: an agent looks "green" long after it wedged. Tower makes fleet state honest — a stale heartbeat is swept to `offline` on a schedule, a blocked agent surfaces first on your phone, and every finished run leaves a receipt that stays **unattested** until something with authority attaches the artifact link.

## Architecture

- **Ingest API** (`/api/v1`, token-authed): synchronous, dumb, fast — validate, insert events, apply the run state machine, return `202`. Everything that can lag runs on a queue.
- **Queue** (`ingest`): `ProcessIngestedBatch` does drift detection, receipt stubs, and broadcast fan-out.
- **Realtime**: Reverb broadcasts `agent.status_changed` / `run.updated` / `drift.raised` / `receipt.updated`; the Livewire board re-renders the touched island. A push is only a refresh *signal* — the server render is the source of truth, and `wire:poll.30s` keeps the board correct if Reverb is down.
- **herdr bridge** (`herdr-plugin/`): a stdlib-only Python daemon that turns a herdr terminal-multiplexer session into a Tower producer. Metadata-only by default; opt-in per-workspace tail capture with local redaction; no reverse channel.
- **Demo agents** (`app/Agents/`, off by default): real agents that dogfood the ingest path and stream a fleet-health briefing over Reverb using the Laravel AI SDK.

Full design: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Requirements: [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md). Delivery plan + evidence: [`docs/delivery/`](docs/delivery/). Frozen contracts: [`docs/contracts/`](docs/contracts/).

## Local development

Requires PHP 8.3+, Composer, Node 20+, and PostgreSQL.

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate

# Postgres (create the databases first: tower, tower_test)
php artisan migrate

# a single admin for the board (registration is disabled)
# set TOWER_ADMIN_EMAIL / TOWER_ADMIN_PASSWORD in .env, then:
php artisan db:seed --class=AdminUserSeeder

# run it: app, websockets, queue worker, scheduler
php artisan serve
php artisan reverb:start
php artisan queue:work --queue=ingest
php artisan schedule:work
```

Board: `/board` (auth). Public read-only board: `/` (only `public` workspaces; safe to share).

## Mint a producer token

Token creation is a human act — there is no self-registration endpoint.

```bash
php artisan tower:agent:create "omp-lane-x" --workspace=work --kind=omp --abilities=ingest,attest
php artisan tower:host:create claude-a --kind=herdr --connect-hint="ssh claude-a"
```

Each prints the plaintext token exactly once; only its SHA-256 is stored.

## Send events

```bash
curl -X POST https://<your-app>/api/v1/events \
  -H "Authorization: Bearer twr_XXXXXXXX" \
  -H "Content-Type: application/json" \
  -d '{
    "schema": "tower.ingest.v1",
    "events": [
      {"type":"run.state_changed","run":{"external_id":"omp:work:001","title":"checkout fix"},
       "to":"running","dedupe_key":"omp:work:001:a","payload":{"tools_used":["bash","edit"]}},
      {"type":"agent.heartbeat"}
    ]
  }'
```

Response is `202` with `{accepted, duplicates, rejected}` — partial acceptance, so one malformed event never drops the batch. Full envelope: [`docs/contracts/tower.ingest.v1.json`](docs/contracts/tower.ingest.v1.json).

## herdr bridge

On a box running [herdr](https://herdr.dev):

```bash
herdr plugin install joshuaswarren/tower/herdr-plugin
# configure tower_url + tower_token in the plugin config, then the daemon
# streams pane state to Tower. Tier 0 (metadata only) is the default.
```

See [`herdr-plugin/README.md`](herdr-plugin/README.md).

## Config

All knobs live in [`config/tower.php`](config/tower.php) (retention, ingest limits, staleness window, drift kill switch, public-board toggle, demo toggle). Demo agents are `TOWER_DEMO_ENABLED=false` by default.

## Deploy to Laravel Cloud

1. Create a Laravel Cloud app from this repo.
2. Attach Serverless Postgres, a Valkey store, and a Reverb cluster (env is injected automatically).
3. Add managed queue workers for the `ingest` and `demo` queues; enable the scheduler.
4. Set `TOWER_ADMIN_EMAIL` / `TOWER_ADMIN_PASSWORD` and your AI provider key (only if enabling demo).
5. Build command includes `npm run build`; deploy on push to `main`.

Scale-to-zero is fine for a personal fork; keep compute always-on only during a live demo/judging window (ADR-0006).

## Testing

```bash
./vendor/bin/pest                      # PHP suite (Postgres-backed)
cd herdr-plugin && make test           # bridge (stdlib Python, unittest)
```

## Posture

- Metadata-only telemetry by default; PTY capture is opt-in per workspace with local redaction before any outbound POST.
- No reverse channel: the board never controls agents.
- Every `done` run leaves a receipt stub; receipts are `unattested` until an authorized source attaches the artifact link.
- Drift is opt-in by declaring an allowlist; uniqueness is enforced by partial unique indexes and concurrency-safe dedup.

## Support

Every bit of support helps keep tower alive and free. If you are able, [sponsor on GitHub](https://github.com/sponsors/joshuaswarren) or send a Lightning donation to `joshuaswarren@strike.me` to directly fund continued development and new integrations.

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-pink?style=for-the-badge)](https://github.com/sponsors/joshuaswarren)

If financial support is not an option, you can still make a big difference: [star the repo](https://github.com/joshuaswarren/tower), share it, or recommend it to a colleague. Word of mouth is how most people find tower.
