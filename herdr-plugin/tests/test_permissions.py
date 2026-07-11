"""File-permission hardening tests (POSIX).

The spool can hold redacted tail content and the state dir sits next to the
token-bearing config, so bridge-created files must not be group/world
accessible. See the lane-C evidence note (trust-boundary review).
"""

from __future__ import annotations

import os
import stat
import tempfile
import unittest
from pathlib import Path

from tests import _path  # noqa: F401
from tower_bridge import Config, _restrict_dir, _secure_touch, spool_append


def _cfg(tmp: Path) -> Config:
    return Config(
        tower_url="https://x", tower_token="t", host="h",
        state_dir=tmp, config_dir=tmp,
    )


@unittest.skipUnless(os.name == "posix", "POSIX permission semantics")
class SpoolPermissionsTest(unittest.TestCase):
    def test_spool_file_is_0600(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            cfg = _cfg(Path(td))
            spool_append(cfg, {"schema": "tower.herdr.v1", "batch": []})
            mode = stat.S_IMODE((Path(td) / "spool.ndjson").stat().st_mode)
            self.assertEqual(mode & 0o077, 0, f"spool group/world bits set: {oct(mode)}")

    def test_secure_touch_creates_0600(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            f = Path(td) / "new.file"
            _secure_touch(f)
            self.assertEqual(stat.S_IMODE(f.stat().st_mode) & 0o077, 0)

    def test_restrict_dir_is_0700(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            d = Path(td) / "state"
            d.mkdir()
            os.chmod(d, 0o755)
            _restrict_dir(d)
            self.assertEqual(stat.S_IMODE(d.stat().st_mode) & 0o077, 0)


if __name__ == "__main__":
    unittest.main()
