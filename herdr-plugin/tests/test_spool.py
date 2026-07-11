"""Tests for spool + replay (resilience layer).

ARCHITECTURE.md §1.6 — POST failure appends the batch to the spool, 5MB
cap oldest-dropped, drop count surfaces on the next envelope. On
reconnect, spool is replayed before live traffic and dedupe_key makes
that server-idempotent.
"""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tests import _path  # noqa: F401
from tower_bridge import Config, spool_append, spool_replay


def _cfg(tmp: Path) -> Config:
    return Config(
        tower_url="https://x", tower_token="t", host="h",
        state_dir=tmp, config_dir=tmp,
    )


class SpoolAppendTest(unittest.TestCase):
    def test_appends_envelope_as_ndjson(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            env = {"schema": "tower.herdr.v1", "batch": [{"kind": "snapshot"}]}
            dropped = spool_append(cfg, env)
            self.assertEqual(dropped, 0)
            content = (Path(td) / "spool.ndjson").read_text()
            self.assertEqual(content.count("\n"), 1)
            self.assertEqual(json.loads(content.strip())["batch"][0]["kind"], "snapshot")

    def test_order_preserved_across_appends(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            for i in range(5):
                spool_append(cfg, {"schema": "tower.herdr.v1", "seq": i})
            lines = (Path(td) / "spool.ndjson").read_text().splitlines()
            self.assertEqual(len(lines), 5)
            seqs = [json.loads(ln)["seq"] for ln in lines]
            self.assertEqual(seqs, [0, 1, 2, 3, 4])


class SpoolReplayTest(unittest.TestCase):
    def test_replay_yields_in_order_then_truncates(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            for i in range(3):
                spool_append(cfg, {"schema": "tower.herdr.v1", "seq": i})
            yielded = [env["seq"] for env in spool_replay(cfg)]
            self.assertEqual(yielded, [0, 1, 2])
            # After replay, the file is gone (truncated).
            self.assertFalse((Path(td) / "spool.ndjson").exists())

    def test_replay_empty_spool(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            self.assertEqual(list(spool_replay(cfg)), [])

    def test_replay_idempotent_by_dedupe_key(self) -> None:
        # The replay content is the same envelopes the daemon tried to POST
        # before. The server uses dedupe_key to make the replay
        # idempotent — verify we don't mutate the payload between spool
        # and replay, so the dedupe_key is stable.
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            env_in = {
                "schema": "tower.herdr.v1",
                "batch": [{"kind": "pane.agent_status_changed",
                           "workspace": "w", "pane": "%1", "to": "blocked",
                           "dedupe_key": "h:%1:42"}],
            }
            spool_append(cfg, env_in)
            replayed = list(spool_replay(cfg))
            self.assertEqual(replayed[0]["batch"][0]["dedupe_key"], "h:%1:42")


class SpoolCapTest(unittest.TestCase):
    def test_drops_oldest_when_over_5mb(self) -> None:
        # Use a smaller cap by patching the module constant — but the
        # implementation hard-codes SPOOL_MAX_BYTES, so we exercise it by
        # appending a payload that pushes us past the cap. We test the
        from tower_bridge import SPOOL_MAX_BYTES
        cap = SPOOL_MAX_BYTES
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            # Write a single line close to the cap, then append another
            # that would push us over.
            big1 = "x" * (cap - 100)
            big2 = "y" * 200
            env1 = {"schema": "tower.herdr.v1", "payload": big1}
            env2 = {"schema": "tower.herdr.v1", "payload": big2}
            spool_append(cfg, env1)
            dropped = spool_append(cfg, env2)
            # We dropped at least one line.
            self.assertGreaterEqual(dropped, 1)
            # Replay still has at least env2 (env1 was dropped).
            replayed = list(spool_replay(cfg))
            self.assertGreaterEqual(len(replayed), 1)
            self.assertEqual(replayed[-1]["payload"], big2)


class SpoolCorruptionTest(unittest.TestCase):
    def test_corrupt_lines_skipped(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            (Path(td) / "spool.ndjson").write_text(
                "not-json\n"
                + json.dumps({"schema": "tower.herdr.v1", "seq": 1}) + "\n"
                + "{garbage}\n"
                + json.dumps({"schema": "tower.herdr.v1", "seq": 2}) + "\n"
            )
            replayed = list(spool_replay(cfg))
            self.assertEqual([e["seq"] for e in replayed], [1, 2])
