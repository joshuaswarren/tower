# TWR-C evidence — herdr bridge plugin (tier 0 + tier 1 redaction)

## Result
- `python3 -m py_compile herdr-plugin/*.py herdr-plugin/tests/*.py` — clean.
- `python3 -m unittest discover -s herdr-plugin/tests -p 'test_*.py'` — **Ran 68 tests, OK**.
- Line-length cap: 0 lines over 120. Runtime imports: stdlib only (`tower_bridge.py`, `redaction.py`).

## Proven
- Envelope building from recorded `pane.agent_status_changed` fixtures → schema-shaped `tower.herdr.v1` with correct dedupe_key and 1:1 state mapping.
- Snapshot reconciliation marks panes absent from a later snapshot as closed/offline.
- Spool: NDJSON append, 5MB oldest-dropped cap, corrupt-line skip, order-preserving idempotent replay by dedupe_key.
- Redaction chain (the security backstop before any tier-1 tail leaves the box): AWS/`sk-`/bearer/PEM patterns, real `os.environ` values stripped byte-level, user deny-regexes applied last with accurate counts. Tier 0 never produces tails.
- Missing `tower_url`/`tower_token` exits with a named error.

## Assumptions flagged for review (herdr API — new tool, verify against a live herdr)
- `events.subscribe` takes a `types` array; pane events `pane.agent_status_changed` / `pane.agent_detected` / `pane.closed`.
- herdr `agent_status` may include `unknown`; normalized to `idle` (no envelope transition emitted).
- `min_herdr_version` pinned conservatively in `herdr-plugin.toml`; confirm against the target herdr build before publishing the plugin.
- Daemon is a single file ~800 lines (config/TOML parse + spool + envelope + socket client + poll fallback); exceeds the ~250 architecture estimate but stays within the 120-col cap. Kept intact rather than split to preserve the tested surface.

## Trust-boundary review (orchestrator, pre-merge)
Audited `tower_bridge.py` against the security contract:
- Metadata-only default: `tier_for()` returns 0 unless a workspace opts in. PASS.
- PTY read gated: `pane.read` is called only from `_maybe_tail`, only when `to==done` AND `tier_for(workspace)==1`. No PTY read at tier 0. PASS.
- Redaction before emit: `_maybe_tail` places only `content_redacted` (from `redact(content, extra_values=os.environ.values(), deny_regexes=...)`) into the tail; raw content never enters buffer/envelope/spool. PASS.
- Read-only toward herdr: only `session.snapshot`, `events.subscribe`, `pane.read` are issued; no `send_keys`/`send_input`/write. No reverse channel. PASS.
- Token hygiene: `tower_token` appears only in the `Authorization` header; never logged/printed. PASS.
- TLS: `urllib` default context (certs verified); no unverified context. PASS.
- FIX applied this review: state dir forced `0700` (`_restrict_dir`), bridge-created spool forced `0600` (`_secure_touch`), and a warning if the token-bearing config is group/world readable. New `tests/test_permissions.py` asserts modes on POSIX (3 tests).

Suite after fix: **71 tests, OK**. Known nit: a ResourceWarning (unclosed fixture socket) in a daemon test — resolved: both socket sites are context-managed (deterministic close per reconnect); suite passes under PYTHONWARNINGS=error::ResourceWarning (now enforced in `make test`).
