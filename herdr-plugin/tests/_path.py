"""Path bootstrap for tests — kept for back-compat.

The bootstrap now lives in tests/__init__.py so it runs whenever tests/
is imported as a package. This file stays so existing `import _path`
lines in test modules remain valid.
"""
from __future__ import annotations

import sys
from pathlib import Path

_PKG = Path(__file__).resolve().parent.parent  # herdr-plugin/
if str(_PKG) not in sys.path:
    sys.path.insert(0, str(_PKG))
