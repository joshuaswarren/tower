"""Structural validator for the tower.herdr.v1 envelope.

This is a *subset* of the JSON Schema at docs/contracts/tower.herdr.v1.json
implemented in stdlib Python. We don't implement the full draft-2020-12
spec — we check the rules the daemon's own output needs to satisfy:

- Top-level required fields and `additionalProperties: false`.
- The `schema` field is the literal `"tower.herdr.v1"`.
- The `tier` enum (0 or 1).
- The `herdrState` enum on `state`/`from`/`to`.
- The `kind` enum on each batch item.
- The allOf conditional `required` for `snapshot` and `pane.agent_status_changed`.
- The `tail` item's required fields and `additionalProperties: false`.
- String length caps (workspace ≤255, pane ≤64, dedupe_key ≤255, host ≤255).

The structural validator is in the test module so it's used at unit-test
time, not by the daemon at runtime — the daemon is read-only and trusts
its own envelope builder. Anything we send that the server rejects is a
real bug we want to catch in CI.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
CONTRACT_PATH = ROOT / "docs" / "contracts" / "tower.herdr.v1.json"

_KIND_ENUM = (
    "snapshot", "pane.agent_status_changed", "pane.agent_detected",
    "pane.closed", "workspace.created", "workspace.closed",
)
_HERDR_STATE_ENUM = ("idle", "working", "blocked", "done")
_TOP_LEVEL = {"schema", "bridge_version", "herdr_version", "host", "tier",
              "sent_at", "batch", "tails", "spool_dropped"}
_TAIL = {"pane", "dedupe_key", "content_redacted", "redactions_applied"}


class ContractViolation(AssertionError):
    """Raised by validate_envelope when the envelope breaks the contract."""


def _load_contract() -> dict[str, Any] | None:
    """Load the contract if it's available; None means we operate headless."""
    if not CONTRACT_PATH.exists():
        return None
    with CONTRACT_PATH.open() as f:
        return json.load(f)


def validate_envelope(env: dict[str, Any], contract: dict[str, Any] | None = None) -> None:
    """Validate `env` against the contract subset. Raises ContractViolation."""
    if contract is None:
        contract = _load_contract()
    if not isinstance(env, dict):
        raise ContractViolation(f"envelope must be object, got {type(env).__name__}")

    for required in ("schema", "host", "tier"):
        if required not in env:
            raise ContractViolation(f"missing top-level field: {required}")
    if env["schema"] != "tower.herdr.v1":
        raise ContractViolation(f"schema must be 'tower.herdr.v1', got {env['schema']!r}")
    extra = set(env) - _TOP_LEVEL
    if extra:
        raise ContractViolation(f"unexpected top-level fields: {sorted(extra)}")

    if not isinstance(env["host"], str) or not (1 <= len(env["host"]) <= 255):
        raise ContractViolation(f"host must be 1..255 chars, got {env['host']!r}")
    if env["tier"] not in (0, 1):
        raise ContractViolation(f"tier must be 0 or 1, got {env['tier']!r}")

    batch = env.get("batch", [])
    if not isinstance(batch, list):
        raise ContractViolation("batch must be array")
    if len(batch) > 200:
        raise ContractViolation(f"batch maxItems=200, got {len(batch)}")

    for idx, item in enumerate(batch):
        _validate_item(item, idx)

    for idx, tail in enumerate(env.get("tails", []) or []):
        _validate_tail(tail, idx)

    if env.get("tails") and env["tier"] != 1:
        raise ContractViolation(f"tier must be 1 when tails present, got {env['tier']}")

    if contract is not None and contract.get("title") != "tower.herdr.v1":
        raise ContractViolation(f"contract title mismatch: {contract.get('title')!r}")


def _validate_item(item: Any, idx: int) -> None:
    if not isinstance(item, dict):
        raise ContractViolation(f"batch[{idx}] must be object, got {type(item).__name__}")
    kind = item.get("kind")
    if kind not in _KIND_ENUM:
        raise ContractViolation(f"batch[{idx}].kind {kind!r} not in {_KIND_ENUM}")

    ws = item.get("workspace")
    if ws is not None and (not isinstance(ws, str) or len(ws) > 255):
        raise ContractViolation(f"batch[{idx}].workspace length/type wrong")
    pane = item.get("pane")
    if pane is not None and (not isinstance(pane, str) or len(pane) > 64):
        raise ContractViolation(f"batch[{idx}].pane length/type wrong")
    dedupe = item.get("dedupe_key")
    if dedupe is not None and (not isinstance(dedupe, str) or len(dedupe) > 255):
        raise ContractViolation(f"batch[{idx}].dedupe_key length/type wrong")

    for field_name in ("from", "to", "state"):
        v = item.get(field_name)
        if v is not None and v not in _HERDR_STATE_ENUM:
            raise ContractViolation(f"batch[{idx}].{field_name} {v!r} not in {_HERDR_STATE_ENUM}")

    if kind == "pane.agent_status_changed":
        for required in ("workspace", "pane", "to", "dedupe_key"):
            if required not in item:
                raise ContractViolation(f"batch[{idx}] pane.agent_status_changed missing {required!r}")
    if kind == "snapshot" and "workspaces" not in item:
        raise ContractViolation(f"batch[{idx}] snapshot missing workspaces")

    if kind == "snapshot":
        for w_idx, ws_node in enumerate(item.get("workspaces") or []):
            if not isinstance(ws_node, dict) or "name" not in ws_node or "panes" not in ws_node:
                raise ContractViolation(f"batch[{idx}].workspaces[{w_idx}] shape wrong")
            for p_idx, pane_node in enumerate(ws_node.get("panes") or []):
                for required in ("pane", "agent_kind", "state"):
                    if required not in pane_node:
                        raise ContractViolation(
                            f"batch[{idx}].workspaces[{w_idx}].panes[{p_idx}] missing {required!r}"
                        )
                if pane_node.get("state") not in _HERDR_STATE_ENUM:
                    raise ContractViolation(
                        f"batch[{idx}].workspaces[{w_idx}].panes[{p_idx}].state invalid"
                    )


def _validate_tail(tail: Any, idx: int) -> None:
    if not isinstance(tail, dict):
        raise ContractViolation(f"tails[{idx}] must be object")
    extra = set(tail) - _TAIL
    if extra:
        raise ContractViolation(f"tails[{idx}] has extra fields: {sorted(extra)}")
    for required in ("pane", "dedupe_key", "content_redacted"):
        if required not in tail:
            raise ContractViolation(f"tails[{idx}] missing {required!r}")
    if not isinstance(tail["dedupe_key"], str) or len(tail["dedupe_key"]) > 255:
        raise ContractViolation(f"tails[{idx}].dedupe_key length/type wrong")
    if not isinstance(tail["pane"], str):
        raise ContractViolation(f"tails[{idx}].pane must be string")
    if not isinstance(tail["content_redacted"], str):
        raise ContractViolation(f"tails[{idx}].content_redacted must be string")
    if "redactions_applied" in tail:
        ra = tail["redactions_applied"]
        if not isinstance(ra, int) or ra < 0:
            raise ContractViolation(f"tails[{idx}].redactions_applied must be non-negative int")
