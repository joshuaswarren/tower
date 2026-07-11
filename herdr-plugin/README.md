# tower.bridge

Read-only bridge plugin that ships herdr workspace/pane state to a
[Tower](https://tower.dev) ingest endpoint as `tower.herdr.v1` envelopes.

The bridge is a Python 3.11+ stdlib-only single-file daemon. It subscribes
to a local herdr server's socket API and POSTs structured envelopes to
`POST <tower_url>/api/v1/herdr` with bearer-token auth. It **never writes
to herdr** — no `send_keys`, no approvals.

## Install

```
herdr plugin install joshuaswarren/tower/herdr-plugin
```

`min_herdr_version` is pinned at `0.6.10`. **Reviewer note:** this is the
value currently recommended by herdr.dev/docs/plugins/ for plugins that
depend on the events.subscribe API and the agent-detected/status-changed
event set used by this bridge. Bump if herdr raises the floor.

## Runtime environment

herdr injects these env vars at plugin launch:

| Var | Purpose |
|---|---|
| `HERDR_SOCKET_PATH` | Unix socket for the herdr server's JSON API |
| `HERDR_BIN_PATH` | Path to the `herdr` CLI (used as a snapshot fallback) |
| `HERDR_PLUGIN_CONFIG_DIR` | Where `config.toml` lives |
| `HERDR_PLUGIN_STATE_DIR` | Where the spool and run state live |

The daemon creates the state dir if missing.

## Config

Copy `config.example.toml` to `config.toml` and edit:

```toml
tower_url  = "https://tower.example.com"
tower_token = "twr_..."     # from: php artisan tower:host:create <host> --kind=herdr
host        = "claude-a"

[workspaces.acme]
tier = 1                    # opt-in to tail content for this workspace
tail_lines = 200            # optional: override default tail length
```

Required keys: `tower_url`, `tower_token`, `host`. The daemon exits
non-zero with a clear message naming the missing keys if any are absent
(unit test in `tests/test_daemon.py`).

### Optional knobs

| Key | Default | Effect |
|---|---|---|
| `flush_interval_seconds` | `2` | Flush buffered events every N seconds |
| `flush_event_count` | `25` | Flush when the buffer reaches N events |
| `tail_lines` | `200` | Lines pulled from a pane on tier-1 `done` |
| `poll_interval_seconds` | (off) | Enables fallback poll mode (see below) |
| `deny_regexes` | `[]` | Extra regexes applied after built-in redactions |

The flush rule is **2 seconds OR 25 events, whichever first** — one
`tower.herdr.v1` POST per flush.

## Telemetry tiers

| Tier | What's sent | PTY content read? |
|---|---|---|
| 0 (default) | Status transitions, agent kind, workspace/pane labels, timestamps, durations | **Never** |
| 1 (opt-in per workspace) | Tier-0 metadata + the last N lines of the pane on a `done` transition | Yes, but always through `redact()` first |

Tier is per-workspace: set `[workspaces.<name>] tier = 1` in `config.toml`.
Any workspace not listed is tier 0.

## Redaction chain

For tier-1 tails, the bridge runs the last N lines through `redact()` in
this order:

1. **Built-in secret-shaped patterns** — AWS access key IDs (`AKIA…`),
   GitHub PATs (`ghp_…`), Slack tokens (`xox[abprs]-…`), OpenAI
   (`sk-…`, `sk-proj-…`), Anthropic (`sk-ant-…`), bearer headers,
   PEM private key blocks, and high-entropy hex strings (≥40 chars).
2. **Env-value stripping** — every value currently in the daemon's
   environment (`os.environ.values()`), exact-match with word boundaries.
   Short values (< 6 chars) are ignored to avoid false positives.
3. **User `deny_regexes`** — your regexes, applied last, marked
   `[REDACTED:user]`.

The `redactions_applied` count is included on every tail entry. The raw
content never leaves the box.

## Fallback poll mode

If `events.subscribe` is unavailable (older herdr, or the subscribe call
returns an error), run the daemon with `--poll 15` to fall back to
polling `session.snapshot` every 15 seconds and diffing against the last
snapshot. The diff synthesizes `pane.agent_status_changed` and
`pane.closed` items so the server side is identical.

You can also enable it in config: `poll_interval_seconds = 15`.

## Resilience

- **POST failure** → exponential backoff (1s → 60s cap) and the batch is
  appended to `HERDR_PLUGIN_STATE_DIR/spool.ndjson` (5 MB cap,
  oldest-dropped). The drop count rides on the next successful envelope
  as `payload.spool_dropped`.
- **Spool replay on reconnect** — when the daemon reconnects (or
  restarts), it replays the spool before live traffic. The server's
  `dedupe_key` unique index makes replay idempotent.
- **Socket loss** → re-run the bootstrap snapshot on reconnect (matches
  ARCHITECTURE.md §1.6 item 3).

## Development

```
make lint     # py_compile both modules
make test     # run the unittest suite
```

Tests use stdlib `unittest` (no `pip install` needed). Pytest is also
fine if you have it — the suite is plain `unittest.TestCase`.

## Files

```
herdr-plugin/
  herdr-plugin.toml         # manifest (id, name, version, min_herdr_version, entrypoint)
  tower_bridge.py           # the daemon
  redaction.py              # pure redaction chain (importable, unit-tested)
  config.example.toml       # sample config with comments
  Makefile                  # lint + test
  tests/
    test_redaction.py       # byte-level redaction correctness
    test_envelope.py        # envelope building + snapshot reconciliation
    test_spool.py           # spool append/replay/drop semantics
    test_daemon.py          # config + tier gating + missing-config exit
    test_integration.py     # end-to-end with fake herdr + Tower
```
