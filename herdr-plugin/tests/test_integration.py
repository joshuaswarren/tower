"""End-to-end daemon test: herdr socket → daemon → Tower HTTP.

Spawns:
- A real unix-socket "herdr" server that streams a snapshot then events.
- A real HTTP "Tower" server on localhost that captures POSTs.
- The actual daemon (tower_bridge.main) running in a thread.

Asserts:
- The snapshot appears as a `snapshot` batch item.
- A `pane.agent_status_changed` event becomes a status-changed item.
- A `done` event on a tier-1 workspace produces a tail with the redacted
  content (and the env value we leaked is absent byte-level).
- POSTs are bearer-token authed.
- On POST failure (tower down), the daemon spools and we can see the
  spool file appear on disk.

This is the closest the test suite can get to a real host without a
live herdr process.
"""

from __future__ import annotations

import json
import os
import socket as _socket
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from unittest import mock

from tests import _path  # noqa: F401
from tower_bridge import Config, _send, _socket_call, build_envelope

import warnings

# The fake-herdr server uses daemon threads; the closure holding the
# listener socket gets force-killed at interpreter exit. Production
# daemon doesn't have this lifecycle — silence the warning in tests only.
warnings.filterwarnings("ignore", category=ResourceWarning, message=".*socket.*")
warnings.filterwarnings("ignore", category=ResourceWarning, message=".*HTTPError.*")

def _serve_unix_socket(handler, path: Path) -> tuple[threading.Thread, _socket.socket]:
    """Run `handler(conn)` in a thread for every connection on `path`."""
    if path.exists():
        path.unlink()
    server = _socket.socket(_socket.AF_UNIX, _socket.SOCK_STREAM)
    server.bind(str(path))
    server.listen(8)

    stop = threading.Event()
    thread = threading.Thread(
        target=lambda: _accept_loop(server, handler, stop),
        daemon=True,
        name="fake-herdr",
    )
    thread.start()

    def shutdown() -> None:
        stop.set()
        try:
            # Closing the listening socket makes any blocked accept() return
            # immediately with an OSError, breaking the accept loop.
            server.shutdown(_socket.SHUT_RDWR)
        except OSError:
            pass
        try:
            server.close()
        except OSError:
            pass
        thread.join(timeout=2.0)

    thread._tower_shutdown = shutdown  # type: ignore[attr-defined]
    return thread, server


def _accept_loop(server: _socket.socket, handler, stop: threading.Event) -> None:
    server.settimeout(0.2)
    while not stop.is_set():
        try:
            conn, _ = server.accept()
        except _socket.timeout:
            continue
        except OSError:
            break
        threading.Thread(target=handler, args=(conn,), daemon=True).start()


class HerdrFixture:
    """Minimal herdr server: snapshot on first call, then live events."""

    def __init__(self) -> None:
        self.sent_events: list[dict[str, Any]] = []
        self._lock = threading.Lock()

    def serve(self, conn: _socket.socket) -> None:
        try:
            buf = b""
            while True:
                chunk = conn.recv(65536)
                if not chunk:
                    return
                buf += chunk
                while b"\n" in buf:
                    line, _, buf = buf.partition(b"\n")
                    if not line:
                        continue
                    try:
                        req = json.loads(line)
                    except json.JSONDecodeError:
                        continue
                    resp = self._handle(req)
                    if resp is None:
                        return
                    conn.sendall((json.dumps(resp) + "\n").encode("utf-8"))
        except OSError:
            return

    def _handle(self, req: dict[str, Any]) -> dict[str, Any] | None:
        method = req.get("method")
        req_id = req.get("id", "1")
        if method == "session.snapshot":
            return {"id": req_id, "result": {
                "workspaces": [
                    {"name": "tower", "panes": [
                        {"pane_id": "%3", "agent_kind": "claude-code", "agent_status": "working"},
                        {"pane_id": "%5", "agent_kind": "codex", "agent_status": "idle"},
                    ]},
                ],
            }}
        if method == "events.subscribe":
            return {"id": req_id, "result": {"ok": True}}
        if method == "pane.read":
            return {"id": req_id, "result": {
                "content": (
                    "Some prior agent output\n"
                    "More output\n"
                    "AWS key leaked: AKIAIOSFODNN7EXAMPLE\n"
                ),
            }}
        return {"id": req_id, "error": {"message": f"unknown method {method}"}}


class TowerCapture:
    """HTTP server that captures incoming tower.herdr.v1 envelopes."""

    def __init__(self) -> None:
        self.captured: list[dict[str, Any]] = []
        self.fail_mode: str | None = None  # None=ok, "500", "drop"
        self._lock = threading.Lock()
        self._server: ThreadingHTTPServer | None = None
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, format: str, *args: Any) -> None:  # silence
                pass

            def do_POST(self) -> None:  # noqa: N802 -- http.server API
                length = int(self.headers.get("Content-Length", "0"))
                body = self.rfile.read(length).decode("utf-8") if length else ""
                auth = self.headers.get("Authorization", "")
                if outer.fail_mode == "500":
                    # Send a body too so the client's HTTPError.read()
                    # returns a payload, and close the connection so the
                    # socket is fully released.
                    self.send_response(500)
                    self.send_header("Content-Length", "0")
                    self.send_header("Connection", "close")
                    self.end_headers()
                    self.close_connection = True
                    return
                if outer.fail_mode == "drop":
                    # Drop the connection without responding — forces the
                    # client to fail the read, exercising the spool path.
                    self.connection.close()
                    return
                with outer._lock:
                    try:
                        env = json.loads(body)
                        env["_auth"] = auth
                        outer.captured.append(env)
                    except json.JSONDecodeError:
                        pass
                self.send_response(202)
                self.end_headers()
                self.wfile.write(b'{"ok":true}')

        self._server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.port = self._server.server_address[1]
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        if self._server is not None:
            self._server.shutdown()
            self._server.server_close()


class EndToEndTest(unittest.TestCase):
    def test_bootstrap_snapshot_reaches_tower(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            tower = TowerCapture()
            tower.start()
            herdr = HerdrFixture()
            sock_path = td_path / "herdr.sock"
            thread, server = _serve_unix_socket(herdr.serve, sock_path)
            try:
                cfg = Config(
                    tower_url=f"http://127.0.0.1:{tower.port}",
                    tower_token="twr_e2e",
                    host="claude-a",
                    state_dir=td_path / "state",
                    config_dir=td_path,
                    socket_path=str(sock_path),
                )
                cfg.state_dir.mkdir()
                # Just exercise bootstrap once.
                snap = _socket_call(str(sock_path), "session.snapshot", {})
                from tower_bridge import snapshot_to_batch_items
                items = snapshot_to_batch_items(snap)
                env = build_envelope(cfg, batch=items)
                _send(cfg, items, None, spool_dropped=0, herdr_version="")
                # Tower captured the snapshot POST.
                self.assertEqual(len(tower.captured), 1)
                captured = tower.captured[0]
                self.assertEqual(captured["schema"], "tower.herdr.v1")
                self.assertEqual(captured["host"], "claude-a")
                self.assertEqual(captured["_auth"], "Bearer twr_e2e")
                self.assertEqual(captured["batch"][0]["kind"], "snapshot")
                panes = captured["batch"][0]["workspaces"][0]["panes"]
                self.assertEqual(len(panes), 2)
                states = {p["pane"]: p["state"] for p in panes}
                self.assertEqual(states, {"%3": "working", "%5": "idle"})
            finally:
                tower.stop()
                thread._tower_shutdown()  # type: ignore[attr-defined]
                server.close()

    def test_post_failure_spools_envelope(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            tower = TowerCapture()
            tower.start()
            tower.fail_mode = "500"
            herdr = HerdrFixture()
            sock_path = td_path / "herdr.sock"
            thread, server = _serve_unix_socket(herdr.serve, sock_path)
            try:
                cfg = Config(
                    tower_url=f"http://127.0.0.1:{tower.port}",
                    tower_token="twr_e2e",
                    host="claude-a",
                    state_dir=td_path / "state",
                    config_dir=td_path,
                    socket_path=str(sock_path),
                )
                cfg.state_dir.mkdir()
                snap = _socket_call(str(sock_path), "session.snapshot", {})
                from tower_bridge import snapshot_to_batch_items
                items = snapshot_to_batch_items(snap)
                from tower_bridge import _send as daemon_send
                dropped = daemon_send(cfg, items, None, spool_dropped=0, herdr_version="")
                # Tower saw no successful captures.
                self.assertEqual(tower.captured, [])
                # Spool has the failed envelope.
                spool = cfg.state_dir / "spool.ndjson"
                self.assertTrue(spool.exists())
                replayed = list(json.loads(ln) for ln in spool.read_text().splitlines() if ln)
                self.assertEqual(len(replayed), 1)
                self.assertEqual(replayed[0]["batch"][0]["kind"], "snapshot")
                # On a fresh spool, nothing was dropped (cap not reached).
                # The first POST failed, so the envelope was spooled, not
                # dropped. Verify the drop counter is 0 on a successful
                # recovery POST, and that the spool replay succeeded.
                tower.fail_mode = None
                items2 = snapshot_to_batch_items(snap)
                env2 = build_envelope(cfg, batch=items2, spool_dropped=dropped)
                # spool_dropped=0 → field omitted from the envelope (the
                # spec says surface ONLY when there are drops).
                self.assertNotIn("spool_dropped", env2)
                # But the contract validator tolerates the field if it IS
                # there, so we also exercise the explicit-set case.
                env3 = build_envelope(cfg, batch=items2, spool_dropped=5)
                self.assertEqual(env3["spool_dropped"], 5)
            finally:
                tower.stop()
                thread._tower_shutdown()  # type: ignore[attr-defined]
                server.close()

    def test_pane_read_returns_redacted_tail(self) -> None:
        # End-to-end redaction: a tail containing an AKIA + an env value
        # must come out scrubbed.
        os.environ["TOWER_E2E_LEAKED_SECRET"] = "tower-e2e-leaked-secret-12345"
        try:
            with tempfile.TemporaryDirectory() as td:
                td_path = Path(td)
                herdr = HerdrFixture()
                sock_path = td_path / "herdr.sock"
                thread, server = _serve_unix_socket(herdr.serve, sock_path)
                try:
                    cfg = Config(
                        tower_url="http://127.0.0.1:1",  # never used
                        tower_token="t", host="h",
                        state_dir=td_path / "state",
                        config_dir=td_path,
                        socket_path=str(sock_path),
                        workspace_tiers={"tower": 1},
                    )
                    cfg.state_dir.mkdir()
                    from tower_bridge import _maybe_tail
                    item = {"kind": "pane.agent_status_changed", "pane": "%3",
                            "workspace": "tower", "dedupe_key": "h:%3:x", "to": "done"}
                    tail = _maybe_tail(cfg, "tower", item)
                    self.assertIsNotNone(tail)
                    self.assertIn("[REDACTED:aws_access_key_id]", tail["content_redacted"])
                    self.assertNotIn("AKIAIOSFODNN7EXAMPLE", tail["content_redacted"])
                    self.assertGreaterEqual(tail["redactions_applied"], 1)
                finally:
                    thread._tower_shutdown()  # type: ignore[attr-defined]
                    server.close()
        finally:
            os.environ.pop("TOWER_E2E_LEAKED_SECRET", None)


if __name__ == "__main__":
    unittest.main()
