"""Tests for daemon config, tier gating, and CLI entrypoint behavior.

This is where we defend the contract that:
- Missing tower_url or tower_token is a hard fail with a named error.
- Tier 0 (default) NEVER produces a tails[] entry.
- Tier 1 produces a tail on `done` transitions only.
- The CLI exit code on bad config is non-zero.

The HTTP POST layer is mocked via a fake server in test_integration.py
(this file focuses on the config + tier surface).
"""

from __future__ import annotations

import json
import socket
import tempfile
import threading
import unittest
from pathlib import Path
from urllib import error as urllib_error

from tests import _path  # noqa: F401  -- bootstrap sys.path
from tower_bridge import (
    Config,
    ConfigError,
    _maybe_tail,
    build_envelope,
    event_to_item,
    load_config,
    main,
)


def _write_config(td: Path, body: str) -> Path:
    p = td / "config.toml"
    p.write_text(body)
    return p


class LoadConfigTest(unittest.TestCase):
    def test_minimal_valid_config(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_url = "https://tower.example.com"\n'
                'tower_token = "twr_test"\n'
                'host = "claude-a"\n'
            ))
            cfg = load_config(path)
            self.assertEqual(cfg.tower_url, "https://tower.example.com")
            self.assertEqual(cfg.tower_token, "twr_test")
            self.assertEqual(cfg.host, "claude-a")
            self.assertEqual(cfg.workspace_tiers, {})
            self.assertEqual(cfg.tier_for("anything"), 0)

    def test_per_workspace_tier(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_url = "https://x"\n'
                'tower_token = "twr_t"\n'
                'host = "h"\n'
                '\n'
                '[workspaces.acme]\n'
                'tier = 1\n'
                'tail_lines = 50\n'
                '\n'
                '[workspaces.public]\n'
                'tier = 0\n'
            ))
            cfg = load_config(path)
            self.assertEqual(cfg.tier_for("acme"), 1)
            self.assertEqual(cfg.tier_for("public"), 0)
            self.assertEqual(cfg.tier_for("unlisted"), 0)
            self.assertEqual(cfg.tail_lines_for("acme"), 50)

    def test_missing_tower_url_exits_named(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_token = "twr_t"\n'
                'host = "h"\n'
            ))
            with self.assertRaisesRegex(ConfigError, "missing required config keys:.*tower_url"):
                load_config(path)

    def test_missing_tower_token_exits_named(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_url = "https://x"\n'
                'host = "h"\n'
            ))
            with self.assertRaisesRegex(ConfigError, "missing required config keys:.*tower_token"):
                load_config(path)

    def test_missing_both_lists_both(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), 'host = "h"\n')
            with self.assertRaisesRegex(ConfigError, "tower_url.*tower_token"):
                load_config(path)

    def test_missing_host_exits_named(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_url = "https://x"\n'
                'tower_token = "t"\n'
            ))
            with self.assertRaisesRegex(ConfigError, "host"):
                load_config(path)

    def test_invalid_tier_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), (
                'tower_url = "x"\n'
                'tower_token = "t"\n'
                'host = "h"\n'
                '\n'
                '[workspaces.acme]\n'
                'tier = 2\n'
            ))
            with self.assertRaisesRegex(ConfigError, "tier must be 0 or 1"):
                load_config(path)

    def test_config_not_found(self) -> None:
        with self.assertRaisesRegex(ConfigError, "config file not found"):
            load_config(Path("/tmp/__nonexistent_tower_bridge_config__.toml"))

    def test_main_exits_2_on_missing_config(self) -> None:
        import sys
        with tempfile.TemporaryDirectory() as td:
            path = _write_config(Path(td), 'host = "h"\n')
            import io
            from contextlib import redirect_stderr
            buf = io.StringIO()
            with redirect_stderr(buf):
                rc = main(["--config", str(path)])
            self.assertEqual(rc, 2)
            self.assertIn("missing required config keys", buf.getvalue())
            self.assertIn("tower_url", buf.getvalue())


class TierGatingTest(unittest.TestCase):
    """Tier 0 must never produce tails; tier 1 must produce tails on `done`."""

    def test_tier_0_envelope_has_no_tails(self) -> None:
        # A `done` transition on a tier-0 workspace must NOT result in a
        # tails[] entry, even though the daemon's event-mapping layer
        # would normally consider triggering a tail.
        item = event_to_item(
            {"type": "pane.agent_status_changed", "pane_id": "%1",
             "workspace_id": "public", "agent_status": "done", "at": "x"},
            "h",
        )
        env = build_envelope(Config(tower_url="x", tower_token="t", host="h"), batch=[item])
        self.assertNotIn("tails", env)
        self.assertEqual(env["tier"], 0)

    def test_tier_1_envelope_promotes_to_tier_1(self) -> None:
        item = event_to_item(
            {"type": "pane.agent_status_changed", "pane_id": "%1",
             "workspace_id": "acme", "agent_status": "done", "at": "x"},
            "h",
        )
        cfg = Config(tower_url="x", tower_token="t", host="h",
                     workspace_tiers={"acme": 1})
        env = build_envelope(cfg, batch=[item])
        self.assertEqual(env["tier"], 1)

    def test_maybe_tail_returns_none_without_socket(self) -> None:
        # No socket path → no tail, even on a `done` event.
        cfg = Config(tower_url="x", tower_token="t", host="h", socket_path="")
        item = {"pane": "%1", "workspace": "acme", "dedupe_key": "h:%1:x"}
        self.assertIsNone(_maybe_tail(cfg, "acme", item))

    def test_maybe_tail_returns_none_without_pane(self) -> None:
        cfg = Config(tower_url="x", tower_token="t", host="h", socket_path="/tmp/x")
        self.assertIsNone(_maybe_tail(cfg, "acme", {"dedupe_key": "x"}))


class StdlibOnlyTest(unittest.TestCase):
    """The runtime modules (tower_bridge.py, redaction.py) must only import
    stdlib modules. Catch accidental third-party imports."""

    STDLIB_PREFIXES = (
        "__future__", "argparse", "json", "logging", "os", "re", "socket",
        "subprocess", "sys", "time", "tomllib", "urllib", "dataclasses",
        "pathlib", "typing", "collections", "contextlib", "io", "tempfile",
        "unittest", "_path", "redaction", "tests",
    )

    def _imports(self, module: str) -> set[str]:
        import importlib
        m = importlib.import_module(module)
        return {name.split(".")[0] for name in dir(m)
                if not name.startswith("_") and isinstance(getattr(m, name, None), type(__import__("os")))}

    def test_tower_bridge_no_third_party_imports(self) -> None:
        import ast
        import sys
        from pathlib import Path
        src = Path("/Users/joshuawarren/src/tower-lane-c/herdr-plugin/tower_bridge.py").read_text()
        tree = ast.parse(src)
        third_party: list[str] = []
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    top = alias.name.split(".")[0]
                    if top not in self.STDLIB_PREFIXES and top not in sys.stdlib_module_names:
                        third_party.append(top)
            elif isinstance(node, ast.ImportFrom) and node.module:
                top = node.module.split(".")[0]
                if top not in self.STDLIB_PREFIXES and top not in sys.stdlib_module_names:
                    third_party.append(top)
        self.assertEqual(third_party, [], f"third-party imports: {third_party}")

    def test_redaction_no_third_party_imports(self) -> None:
        import ast
        import sys
        from pathlib import Path
        src = Path("/Users/joshuawarren/src/tower-lane-c/herdr-plugin/redaction.py").read_text()
        tree = ast.parse(src)
        third_party: list[str] = []
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    top = alias.name.split(".")[0]
                    if top not in self.STDLIB_PREFIXES and top not in sys.stdlib_module_names:
                        third_party.append(top)
            elif isinstance(node, ast.ImportFrom) and node.module:
                top = node.module.split(".")[0]
                if top not in self.STDLIB_PREFIXES and top not in sys.stdlib_module_names:
                    third_party.append(top)
        self.assertEqual(third_party, [], f"third-party imports: {third_party}")


if __name__ == "__main__":
    unittest.main()
