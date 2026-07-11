"""tower.bridge — read-only bridge from herdr to Tower.

Subscribes to a local herdr server (herdr.dev) over its unix socket API,
builds `tower.herdr.v1` envelopes, and POSTs them to a Tower ingest
endpoint with bearer-token auth. Tier 0 = metadata only (no PTY content
ever). Tier 1 = on a `done` transition, the last N lines of the pane are
pulled, run through the redaction chain, and emitted as a `tails[]` entry.

This file is intentionally a single-file daemon (Python 3.11+, stdlib
only). It is the daemon process the herdr-plugin.toml entrypoint points
at. It NEVER writes to herdr (no send_keys, no approvals).
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
import tomllib
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Iterable

from redaction import redact

LOG = logging.getLogger("tower_bridge")

# ---- Constants (match docs/contracts/tower.herdr.v1.json) -----------------
SCHEMA = "tower.herdr.v1"
BRIDGE_VERSION = "0.1.0"

# Per ARCHITECTURE.md §1.6 — flush every 2s OR 25 events, whichever first.
DEFAULT_FLUSH_INTERVAL_S = 2.0
DEFAULT_FLUSH_EVENT_COUNT = 25
DEFAULT_TAIL_LINES = 200
DEFAULT_BACKOFF_CAP_S = 60.0
SPOOL_MAX_BYTES = 5 * 1024 * 1024  # 5MB cap, oldest-dropped

# herdr agent_status values (herdr.dev/docs/agents/). The envelope enum is
# the 4-value subset idle/working/blocked/done. `unknown` is treated as
# non-transitioning and surfaces as `idle` so the board still has a state.
HERDR_STATES = ("idle", "working", "blocked", "done")
_KNOWN_EVENT_TYPES = ("pane.agent_status_changed", "pane.agent_detected", "pane.closed",
                      "workspace.created", "workspace.closed")


# ---- Config ---------------------------------------------------------------

class ConfigError(RuntimeError):
    """Raised when the bridge can't start without operator action."""


@dataclass
class Config:
    tower_url: str
    tower_token: str
    host: str
    workspace_tiers: dict[str, int] = field(default_factory=dict)
    workspace_tail_lines: dict[str, int] = field(default_factory=dict)
    deny_regexes: list[str] = field(default_factory=list)
    flush_interval_s: float = DEFAULT_FLUSH_INTERVAL_S
    flush_event_count: int = DEFAULT_FLUSH_EVENT_COUNT
    default_tail_lines: int = DEFAULT_TAIL_LINES
    poll_interval_s: float | None = None
    state_dir: Path = field(default_factory=Path)
    config_dir: Path = field(default_factory=Path)
    socket_path: str = ""
    bin_path: str | None = None

    def tier_for(self, workspace: str) -> int:
        return self.workspace_tiers.get(workspace, 0)

    def tail_lines_for(self, workspace: str) -> int:
        return self.workspace_tail_lines.get(workspace, self.default_tail_lines)


def _restrict_dir(path: Path) -> None:
    """Best-effort 0700 on the POSIX state dir (it holds the spool)."""
    if os.name == "posix":
        try:
            os.chmod(path, 0o700)
        except OSError as exc:  # pragma: no cover - platform/fs dependent
            LOG.warning("could not restrict %s to 0700: %s", path, exc)


def _warn_if_config_readable(path: Path) -> None:
    """Warn (never fail) if the token-bearing config is group/world readable."""
    if os.name == "posix" and path.exists() and (path.stat().st_mode & 0o077):
        LOG.warning("config %s is group/world accessible; chmod 600 recommended", path)


def _secure_touch(path: Path) -> None:
    """Ensure a bridge-created file exists with 0600 before writing to it."""
    if not path.exists():
        os.close(os.open(path, os.O_CREAT | os.O_WRONLY, 0o600))
    elif os.name == "posix":
        try:
            os.chmod(path, 0o600)
        except OSError:  # pragma: no cover
            pass


def load_config(path: Path) -> Config:
    """Parse a config.toml into a typed Config, validating required keys."""
    if not path.exists():
        raise ConfigError(f"config file not found: {path}")
    with path.open("rb") as f:
        parsed = tomllib.load(f)
    missing = [k for k in ("tower_url", "tower_token") if not parsed.get(k)]
    if missing:
        raise ConfigError(f"missing required config keys: {', '.join(missing)} (set in {path})")
    if not parsed.get("host"):
        raise ConfigError(f"missing required config key: host (set in {path})")

    workspace_tiers: dict[str, int] = {}
    workspace_tail_lines: dict[str, int] = {}
    workspaces = parsed.get("workspaces") or {}
    for name, body in workspaces.items():
        if not isinstance(body, dict):
            continue
        tier = int(body.get("tier", 0))
        if tier not in (0, 1):
            raise ConfigError(f"workspace {name!r}: tier must be 0 or 1, got {tier!r}")
        workspace_tiers[name] = tier
        if "tail_lines" in body:
            workspace_tail_lines[name] = int(body["tail_lines"])

    config_dir = Path(os.environ.get("HERDR_PLUGIN_CONFIG_DIR", str(path.parent)))
    state_dir = Path(os.environ.get("HERDR_PLUGIN_STATE_DIR", str(config_dir / "state")))
    state_dir.mkdir(parents=True, exist_ok=True)
    _restrict_dir(state_dir)
    _warn_if_config_readable(path)

    return Config(
        tower_url=str(parsed["tower_url"]).rstrip("/"),
        tower_token=str(parsed["tower_token"]),
        host=str(parsed["host"]),
        workspace_tiers=workspace_tiers,
        workspace_tail_lines=workspace_tail_lines,
        deny_regexes=[str(r) for r in parsed.get("deny_regexes", []) or []],
        flush_interval_s=float(parsed.get("flush_interval_seconds", DEFAULT_FLUSH_INTERVAL_S)),
        flush_event_count=int(parsed.get("flush_event_count", DEFAULT_FLUSH_EVENT_COUNT)),
        default_tail_lines=int(parsed.get("tail_lines", DEFAULT_TAIL_LINES)),
        poll_interval_s=float(parsed["poll_interval_seconds"]) if "poll_interval_seconds" in parsed else None,
        state_dir=state_dir,
        config_dir=config_dir,
        socket_path=os.environ.get("HERDR_SOCKET_PATH", ""),
        bin_path=os.environ.get("HERDR_BIN_PATH") or None,
    )


# ---- Envelope builders -----------------------------------------------------

def _iso_now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def _herdr_version(cfg: Config) -> str:
    """Best-effort herdr version probe; empty string on failure."""
    if not cfg.bin_path:
        return ""
    try:
        out = subprocess.run(
            [cfg.bin_path, "--version"], capture_output=True, text=True, timeout=3, check=False,
        )
        text = (out.stdout or out.stderr or "").strip()
        m = re.search(r"\d+\.\d+\.\d+", text)
        return m.group(0) if m else text
    except (OSError, subprocess.TimeoutExpired):
        return ""


def build_envelope(
    cfg: Config, batch: list[dict[str, Any]],
    *, tails: list[dict[str, Any]] | None = None,
    spool_dropped: int = 0, herdr_version: str = "",
) -> dict[str, Any]:
    """Build a tower.herdr.v1 envelope from a batch of items.

    The `tier` reported on the envelope is the MAX tier of any item in the
    batch — Tower uses this to decide whether to accept tails[].
    """
    max_tier = 1 if tails else int(any(
        isinstance(i.get("workspace"), str) and cfg.tier_for(i["workspace"]) == 1
        for i in batch
    ))
    env: dict[str, Any] = {
        "schema": SCHEMA, "bridge_version": BRIDGE_VERSION, "host": cfg.host,
        "tier": max_tier, "sent_at": _iso_now(), "batch": batch,
    }
    if herdr_version:
        env["herdr_version"] = herdr_version
    if tails:
        env["tails"] = tails
    if spool_dropped:
        # `spool_dropped` rides along on the envelope — see ARCHITECTURE.md
        # §1.6 (resilience) — so the server can surface it on the next POST.
        env["spool_dropped"] = int(spool_dropped)
    return env


def dedupe_key(host: str, pane: str, seq: int | str) -> str:
    return f"{host}:{pane}:{seq}"


# ---- Snapshot + event mapping --------------------------------------------

def _normalize_state(raw: str) -> str:
    """Map herdr's 5-value agent_status to the 4-value envelope enum.

    `unknown` is folded into `idle` — it's a non-transition and the board
    needs a real state to render.
    """
    if raw in HERDR_STATES:
        return raw
    return "idle"


def _socket_call(socket_path: str, method: str, params: dict[str, Any]) -> dict[str, Any]:
    """Send one newline-delimited JSON request over the herdr unix socket."""
    payload = {"id": str(time.time_ns()), "method": method, "params": params}
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
        s.settimeout(10)
        s.connect(socket_path)
        s.sendall((json.dumps(payload) + "\n").encode("utf-8"))
        chunks: list[bytes] = []
        while True:
            try:
                buf = s.recv(65536)
            except socket.timeout:
                break
            if not buf:
                break
            chunks.append(buf)
            if b"\n" in buf:
                break
    parsed = json.loads(b"".join(chunks).strip())
    if "error" in parsed:
        raise ConfigError(f"herdr {method} returned error: {parsed['error']}")
    return parsed.get("result", {})


def _read_snapshot(cfg: Config) -> dict[str, Any]:
    """Read the herdr workspace/pane snapshot.

    Tries `HERDR_BIN_PATH api snapshot` first; falls back to a raw
    `session.snapshot` over the unix socket.
    """
    if cfg.bin_path:
        try:
            out = subprocess.run(
                [cfg.bin_path, "api", "snapshot"],
                capture_output=True, text=True, timeout=10, check=True,
            )
            return json.loads(out.stdout)
        except (OSError, subprocess.TimeoutExpired, subprocess.CalledProcessError, json.JSONDecodeError) as exc:
            LOG.warning("herdr api snapshot failed (%s); falling back to raw socket", exc)
    if cfg.socket_path:
        return _socket_call(cfg.socket_path, "session.snapshot", {})
    raise ConfigError("cannot read snapshot: HERDR_BIN_PATH and HERDR_SOCKET_PATH both unavailable")


def snapshot_to_batch_items(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    """Translate a herdr snapshot dict into one `snapshot` batch item.

    The herdr snapshot shape is documented at herdr.dev/docs/socket-api/.
    Expected: {workspaces: [{name, panes: [{pane_id, agent_kind, agent_status}]}]}.
    Unknown keys are tolerated.
    """
    out_workspaces: list[dict[str, Any]] = []
    for ws in snapshot.get("workspaces") or []:
        out_panes: list[dict[str, Any]] = []
        for p in ws.get("panes") or []:
            pane_id = p.get("pane_id")
            if not isinstance(pane_id, str):
                continue
            out_panes.append({
                "pane": pane_id,
                "agent_kind": str(p.get("agent_kind") or p.get("agent") or ""),
                "state": _normalize_state(str(p.get("agent_status") or "idle")),
            })
        if out_panes:
            out_workspaces.append({"name": str(ws.get("name") or ""), "panes": out_panes})
    return [{"kind": "snapshot", "workspaces": out_workspaces}]


def reconcile_snapshot(prev: dict[str, Any], curr: dict[str, Any]) -> list[dict[str, Any]]:
    """Emit `pane.closed` for panes missing from the new snapshot."""
    def pane_keys(item: dict[str, Any]) -> set[tuple[str, str]]:
        return {(ws.get("name"), p.get("pane"))
                for ws in item.get("workspaces") or []
                for p in ws.get("panes") or []
                if isinstance(ws.get("name"), str) and isinstance(p.get("pane"), str)}
    now = int(time.time())
    return [
        {"kind": "pane.closed", "workspace": ws, "pane": pane, "at": _iso_now(),
         "dedupe_key": dedupe_key("", pane, f"snap-missing-{now}-{ws}")}
        for ws, pane in sorted(pane_keys(prev) - pane_keys(curr))
    ]


def event_to_item(event: dict[str, Any], host: str) -> dict[str, Any] | None:
    """Translate a herdr subscription event into a batch item.

    Field names follow herdr's Socket API: `type`, `pane_id`, `agent_status`,
    optional `from`/`prev_state`, `agent_kind`/`agent`. Items with no
    recognizable pane_id are dropped.
    """
    etype = event.get("type")
    if etype not in _KNOWN_EVENT_TYPES:
        return None
    workspace = event.get("workspace_id") or event.get("workspace")
    at = event.get("at") or _iso_now()
    seq = event.get("seq") or event.get("revision") or at
    if etype in ("workspace.created", "workspace.closed"):
        if not isinstance(workspace, str) or not workspace:
            return None
        return {"kind": etype, "workspace": workspace, "at": at,
                "dedupe_key": dedupe_key(host, workspace, at)}
    pane_id = event.get("pane_id")
    if not isinstance(pane_id, str) or not pane_id:
        return None
    base: dict[str, Any] = {"at": at, "dedupe_key": dedupe_key(host, pane_id, seq),
                            "pane": pane_id}
    if isinstance(workspace, str) and workspace:
        base["workspace"] = workspace
    if etype == "pane.agent_status_changed":
        base["kind"] = etype
        base["to"] = _normalize_state(str(event.get("agent_status") or event.get("to") or "idle"))
        prev_state = event.get("from") if "from" in event else event.get("prev_state")
        if prev_state is not None:
            base["from"] = _normalize_state(str(prev_state))
        if event.get("agent_kind") or event.get("agent"):
            base["agent_kind"] = str(event.get("agent_kind") or event.get("agent"))
    elif etype == "pane.agent_detected":
        base["kind"] = etype
        base["agent_kind"] = str(event.get("agent_kind") or event.get("agent") or "")
        base["to"] = _normalize_state(str(event.get("agent_status") or "idle"))
    elif etype == "pane.closed":
        base["kind"] = etype
    return base


# ---- Spool -----------------------------------------------------------------

def _spool_path(cfg: Config) -> Path:
    return cfg.state_dir / "spool.ndjson"


def spool_append(cfg: Config, envelope: dict[str, Any]) -> int:
    """Append an envelope to the spool, dropping oldest lines if over the cap.

    Returns the number of dropped lines (so we can surface it on the next
    successful POST). The drop count is operator-visible per
    ARCHITECTURE.md §1.6 (item 3, resilience).
    """
    path = _spool_path(cfg)
    _secure_touch(path)
    line = json.dumps(envelope, separators=(",", ":")).encode("utf-8") + b"\n"
    if path.exists() and path.stat().st_size + len(line) > SPOOL_MAX_BYTES:
        data = path.read_bytes()
        keep_from = max(0, len(data) - (SPOOL_MAX_BYTES - len(line)))
        nl = data.find(b"\n", keep_from)
        if nl == -1:
            dropped, data = (data.count(b"\n"), b"")
        else:
            dropped, data = data[:nl].count(b"\n") + 1, data[nl + 1:]
        path.write_bytes(data + line)
        return dropped
    with path.open("ab") as f:
        f.write(line)
    return 0


def spool_replay(cfg: Config) -> Iterable[dict[str, Any]]:
    """Yield envelopes from the spool in insertion order, then truncate."""
    path = _spool_path(cfg)
    if not path.exists():
        return
    for line in path.read_bytes().splitlines():
        if not line:
            continue
        try:
            yield json.loads(line)
        except json.JSONDecodeError:
            continue
    try:
        path.unlink()
    except FileNotFoundError:
        pass


# ---- HTTP POST -------------------------------------------------------------

def post_envelope(cfg: Config, envelope: dict[str, Any], *, timeout: float = 10.0) -> None:
    """POST a tower.herdr.v1 envelope. Raises on non-2xx."""
    body = json.dumps(envelope, separators=(",", ":")).encode("utf-8")
    req = urllib.request.Request(
        f"{cfg.tower_url}/api/v1/herdr", data=body, method="POST",
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {cfg.tower_token}",
            "User-Agent": f"tower.bridge/{BRIDGE_VERSION}",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            resp.read()  # drain body so the connection is reusable / closed cleanly
    except urllib.error.HTTPError as exc:
        # Drain the body to release the underlying socket.
        try:
            exc.read()
        except Exception:  # noqa: BLE001
            pass
        raise

def _send(cfg: Config, batch: list[dict[str, Any]], tails: list[dict[str, Any]] | None,
          *, spool_dropped: int, herdr_version: str) -> int:
    """Build envelope, POST it. On failure, spool and raise. Returns drop count.

    The caller threads the running drop counter through: a successful
    POST clears the carry (returns 0), a failed POST appends to the
    spool and returns the new drop count.
    """
    env = build_envelope(cfg, batch=batch, tails=tails,
                         spool_dropped=spool_dropped, herdr_version=herdr_version)
    try:
        post_envelope(cfg, env)
    except urllib.error.HTTPError as exc:
        # Drain the body to release the underlying socket, then collapse
        # the exception to a string so LOG doesn't keep a reference to
        # the HTTPError (which holds the response body).
        try:
            exc.read()
        except Exception:  # noqa: BLE001
            pass
        msg = f"HTTP {exc.code} {exc.reason}"
        LOG.warning("POST failed (%s); spooling %d items", msg, len(batch))
        return spool_dropped + spool_append(cfg, env)
    except (urllib.error.URLError, OSError) as exc:
        LOG.warning("POST failed (%s); spooling %d items", exc, len(batch))
        return spool_dropped + spool_append(cfg, env)
    return 0


def _flush(cfg: Config, buffered: list[dict[str, Any]], tails: list[dict[str, Any]],
           *, spool_dropped: int, herdr_version: str) -> int:
    if not buffered and not tails:
        return spool_dropped
    return _send(cfg, buffered, tails or None,
                 spool_dropped=spool_dropped, herdr_version=herdr_version)


def _read_socket_lines(sock: socket.socket) -> Iterable[bytes]:
    """Yield complete newline-terminated frames from a blocking socket."""
    buf = b""
    while chunk := sock.recv(65536):
        buf += chunk
        while b"\n" in buf:
            line, _, buf = buf.partition(b"\n")
            if line:
                yield line
        if not chunk:
            break


def _maybe_tail(cfg: Config, workspace: str, item: dict[str, Any]) -> dict[str, Any] | None:
    """Tier-1 only: pane.read the tail and redact it."""
    if not cfg.socket_path:
        return None
    pane = item.get("pane")
    if not pane:
        return None
    try:
        result = _socket_call(cfg.socket_path, "pane.read",
                              {"pane_id": pane, "lines": cfg.tail_lines_for(workspace)})
    except (OSError, ConfigError, json.JSONDecodeError) as exc:
        LOG.warning("pane.read failed for %s: %s", pane, exc)
        return None
    content = result.get("content") or result.get("text") or ""
    if not isinstance(content, str):
        content = str(content)
    redacted, n_redactions = redact(
        content, extra_values=list(os.environ.values()), deny_regexes=cfg.deny_regexes,
    )
    return {
        "pane": pane,
        "dedupe_key": item.get("dedupe_key", dedupe_key(cfg.host, pane, int(time.time() * 1000))),
        "content_redacted": redacted,
        "redactions_applied": n_redactions,
    }


def _run_subscribe_session(cfg: Config, herdr_version: str) -> None:
    """One session: bootstrap snapshot + spool replay, then stream events."""
    if not cfg.socket_path:
        raise ConfigError("HERDR_SOCKET_PATH not set")
    snap_item = _read_snapshot(cfg)
    snap_items = snapshot_to_batch_items(snap_item)
    spool_dropped = 0
    for prior in list(spool_replay(cfg)):
        spool_dropped = _send(cfg, prior.get("batch") or [], prior.get("tails"),
                              spool_dropped=spool_dropped, herdr_version=herdr_version)
    spool_dropped = _send(cfg, snap_items, None,
                          spool_dropped=spool_dropped, herdr_version=herdr_version)

    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
        s.settimeout(cfg.flush_interval_s * 2)
        s.connect(cfg.socket_path)
        s.sendall((json.dumps({"id": str(time.time_ns()), "method": "events.subscribe",
                               "params": {"types": list(_KNOWN_EVENT_TYPES)}}) + "\n").encode("utf-8"))
        buffered: list[dict[str, Any]] = []
        tails: list[dict[str, Any]] = []
        last_flush = time.monotonic()
        for raw in _read_socket_lines(s):
            try:
                evt = json.loads(raw)
            except json.JSONDecodeError:
                continue
            if "error" in evt and not evt.get("result"):
                raise ConfigError(f"herdr subscribe error: {evt['error']}")
            event = evt.get("result") if isinstance(evt.get("result"), dict) else evt
            if not isinstance(event, dict):
                continue
            item = event_to_item(event, cfg.host)
            if item is None:
                continue
            buffered.append(item)
            if (item.get("kind") == "pane.agent_status_changed" and item.get("to") == "done"
                    and cfg.tier_for(item.get("workspace", "")) == 1):
                tail = _maybe_tail(cfg, item.get("workspace", ""), item)
                if tail is not None:
                    tails.append(tail)
            if (time.monotonic() - last_flush >= cfg.flush_interval_s
                    or len(buffered) >= cfg.flush_event_count):
                spool_dropped = _flush(cfg, buffered, tails, spool_dropped=spool_dropped,
                                       herdr_version=herdr_version)
                buffered, tails, last_flush = [], [], time.monotonic()


def _subscribe_loop(cfg: Config, herdr_version: str) -> None:
    """Long-lived events.subscribe loop. Reconnects on socket loss."""
    backoff = 1.0
    while True:
        try:
            _run_subscribe_session(cfg, herdr_version)
            backoff = 1.0
        except (OSError, socket.error, ConfigError) as exc:
            LOG.warning("subscribe session ended (%s); reconnecting in %.1fs", exc, backoff)
            time.sleep(backoff)
            backoff = min(backoff * 2, DEFAULT_BACKOFF_CAP_S)


def _run_poll_mode(cfg: Config, herdr_version: str) -> None:
    """Fallback: poll session.snapshot on a fixed interval, diff against last."""
    interval = cfg.poll_interval_s or 15.0
    prev: dict[str, Any] | None = None
    spool_dropped = 0
    while True:
        try:
            curr = _read_snapshot(cfg)
        except (OSError, ConfigError, json.JSONDecodeError) as exc:
            LOG.warning("poll snapshot failed (%s); retrying in %.1fs", exc, interval)
            time.sleep(interval)
            continue
        batch: list[dict[str, Any]] = snapshot_to_batch_items(curr)
        if prev is not None:
            batch.extend(reconcile_snapshot(prev, curr))
        spool_dropped = _flush(cfg, batch, None, spool_dropped=spool_dropped,
                               herdr_version=herdr_version)
        prev = curr
        time.sleep(interval)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Read-only herdr → Tower bridge (tower.bridge plugin).",
    )
    parser.add_argument("--config", type=Path, default=None,
                        help="Path to config.toml (default: $HERDR_PLUGIN_CONFIG_DIR/config.toml).")
    parser.add_argument("--poll", type=float, default=None, metavar="SECONDS",
                        help="Fallback poll mode: snapshot every N seconds (default off).")
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args(argv)
    logging.basicConfig(level=getattr(logging, args.log_level.upper(), logging.INFO),
                        format="%(asctime)s %(levelname)s %(name)s %(message)s")
    config_path = args.config or Path(os.environ.get("HERDR_PLUGIN_CONFIG_DIR", ".")) / "config.toml"
    try:
        cfg = load_config(config_path)
    except ConfigError as exc:
        print(f"tower_bridge: {exc}", file=sys.stderr)
        return 2
    herdr_version = _herdr_version(cfg)
    if args.poll is not None or cfg.poll_interval_s is not None:
        _run_poll_mode(cfg, herdr_version)
    else:
        _subscribe_loop(cfg, herdr_version)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
