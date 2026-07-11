"""Make herdr-plugin/ importable when tests/ runs from the worktree root.

Used when running tests via `python -m unittest discover -s tests -t tests`.
For `python -m unittest tests.test_xxx`, Python adds the tests directory
to sys.path implicitly, so this module finds the herdr-plugin dir via
__file__.
"""

from __future__ import annotations

import sys
from pathlib import Path

# __init__.py is the package marker. tests/_path.py is the boot.
_HERE = Path(__file__).resolve().parent
_PKG = _HERE.parent  # herdr-plugin/
if str(_PKG) not in sys.path:
    sys.path.insert(0, str(_PKG))
