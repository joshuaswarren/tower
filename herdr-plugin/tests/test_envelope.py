"""Tests for envelope building, snapshot reconciliation, and event mapping.

The envelope is the only thing the daemon sends to Tower, so these tests
defend the wire contract. We run every built envelope through the
structural validator (see _envelope_validator.py) which mirrors the JSON
Schema at docs/contracts/tower.herdr.v1.json.
"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from tests import _path  # noqa: F401
from tower_bridge import (
    Config,
    build_envelope,
    dedupe_key,
    event_to_item,
    snapshot_to_batch_items,
)
from tests._envelope_validator import validate_envelope, ContractViolation


def _cfg(tiers: dict[str, int] | None = None) -> Config:
    """Build a minimal Config for envelope tests — no I/O paths touched."""
    return Config(
        tower_url="https://tower.example.com",
        tower_token="twr_test",
        host="claude-a",
        workspace_tiers=tiers or {},
        state_dir=Path("/tmp"),
        config_dir=Path("/tmp"),
    )


class EnvelopeBuildingTest(unittest.TestCase):
    def test_minimal_envelope_validates(self) -> None:
        env = build_envelope(_cfg(), batch=[])
        validate_envelope(env)

    def test_snapshot_envelope_validates(self) -> None:
        snap = {"workspaces": [{"name": "tower", "panes": [
            {"pane_id": "%3", "agent_kind": "claude-code", "agent_status": "working"},
        ]}]}
        env = build_envelope(_cfg(), batch=snapshot_to_batch_items(snap))
        validate_envelope(env)
        self.assertEqual(env["tier"], 0)
        self.assertEqual(env["batch"][0]["kind"], "snapshot")
        self.assertEqual(env["batch"][0]["workspaces"][0]["panes"][0]["state"], "working")

    def test_pane_agent_status_changed_envelope_validates(self) -> None:
        # Recorded fixture: a herdr `pane.agent_status_changed` event,
        # translated to a tower.herdr.v1 batch item.
        event = {
            "type": "pane.agent_status_changed",
            "pane_id": "%3",
            "workspace_id": "tower",
            "from": "working",
            "agent_status": "blocked",
            "agent_kind": "claude-code",
            "at": "2026-07-12T09:15:02Z",
            "seq": 8842,
        }
        item = event_to_item(event, "claude-a")
        self.assertIsNotNone(item)
        self.assertEqual(item["kind"], "pane.agent_status_changed")
        self.assertEqual(item["workspace"], "tower")
        self.assertEqual(item["pane"], "%3")
        self.assertEqual(item["from"], "working")
        self.assertEqual(item["to"], "blocked")
        self.assertEqual(item["agent_kind"], "claude-code")
        # dedupe_key 1:1 with herdr state: host : pane : seq.
        self.assertEqual(item["dedupe_key"], "claude-a:%3:8842")
        env = build_envelope(_cfg(), batch=[item])
        validate_envelope(env)

    def test_state_mapping_1to1(self) -> None:
        for raw, expected in [("idle", "idle"), ("working", "working"),
                              ("blocked", "blocked"), ("done", "done"),
                              ("unknown", "idle"),  # unknown folds to idle
                              ("", "idle"),         # missing folds to idle
                              ("garbage", "idle")]:
            event = {"type": "pane.agent_status_changed", "pane_id": "%1",
                     "workspace_id": "w", "agent_status": raw}
            item = event_to_item(event, "h")
            self.assertEqual(item["to"], expected, f"raw={raw!r}")

    def test_tier_1_workspace_promotes_envelope_tier(self) -> None:
        item = event_to_item(
            {"type": "pane.agent_status_changed", "pane_id": "%1",
             "workspace_id": "acme", "agent_status": "done", "at": "2026-07-12T00:00:00Z"},
            "h",
        )
        env = build_envelope(_cfg(tiers={"acme": 1}), batch=[item])
        self.assertEqual(env["tier"], 1)

    def test_tier_0_workspace_envelope_is_tier_0(self) -> None:
        item = event_to_item(
            {"type": "pane.agent_status_changed", "pane_id": "%1",
             "workspace_id": "public", "agent_status": "done", "at": "x"},
            "h",
        )
        env = build_envelope(_cfg(tiers={"public": 0}), batch=[item])
        self.assertEqual(env["tier"], 0)

    def test_unknown_event_kind_dropped(self) -> None:
        event = {"type": "pane.focused", "pane_id": "%3", "workspace_id": "w"}
        self.assertIsNone(event_to_item(event, "h"))

    def test_event_without_pane_id_dropped(self) -> None:
        event = {"type": "pane.closed"}
        self.assertIsNone(event_to_item(event, "h"))

    def test_pane_closed_event(self) -> None:
        item = event_to_item(
            {"type": "pane.closed", "pane_id": "%3", "workspace_id": "w",
             "at": "2026-07-12T00:00:00Z"},
            "h",
        )
        self.assertEqual(item["kind"], "pane.closed")
        self.assertEqual(item["dedupe_key"], "h:%3:2026-07-12T00:00:00Z")
        env = build_envelope(_cfg(), batch=[item])
        validate_envelope(env)

    def test_pane_agent_detected_event(self) -> None:
        item = event_to_item(
            {"type": "pane.agent_detected", "pane_id": "%5", "workspace_id": "w",
             "agent_kind": "codex", "agent_status": "working", "at": "x"},
            "h",
        )
        self.assertEqual(item["kind"], "pane.agent_detected")
        self.assertEqual(item["agent_kind"], "codex")
        env = build_envelope(_cfg(), batch=[item])
        validate_envelope(env)

    def test_workspace_lifecycle_events(self) -> None:
        for etype, expected_kind in (("workspace.created", "workspace.created"),
                                     ("workspace.closed", "workspace.closed")):
            item = event_to_item(
                {"type": etype, "workspace_id": "acme", "at": "x"},
                "h",
            )
            self.assertEqual(item["kind"], expected_kind)
            self.assertEqual(item["workspace"], "acme")
            env = build_envelope(_cfg(), batch=[item])
            validate_envelope(env)

    def test_dedupe_key_format(self) -> None:
        self.assertEqual(dedupe_key("h", "%3", 42), "h:%3:42")
        self.assertEqual(dedupe_key("h", "%3", "abc"), "h:%3:abc")

    def test_top_level_no_additional_fields(self) -> None:
        # Build with no optional fields; the only keys must be the contract's
        # required + always-present fields.
        env = build_envelope(_cfg(), batch=[])
        self.assertEqual(
            set(env),
            {"schema", "bridge_version", "host", "tier", "sent_at", "batch"},
        )

    def test_bridge_version_constant(self) -> None:
        env = build_envelope(_cfg(), batch=[])
        self.assertEqual(env["bridge_version"], "0.1.0")
        self.assertEqual(env["schema"], "tower.herdr.v1")

    def test_spool_dropped_rides_on_envelope(self) -> None:
        env = build_envelope(_cfg(), batch=[], spool_dropped=7)
        self.assertEqual(env["spool_dropped"], 7)
        validate_envelope(env)  # server tolerates this known addition

    def test_tails_only_when_tier_1(self) -> None:
        # The contract test for tier=0 + tails: the validator should reject
        # the malformed envelope.
        item = event_to_item(
            {"type": "pane.agent_status_changed", "pane_id": "%1",
             "workspace_id": "w", "agent_status": "done", "at": "x"},
            "h",
        )
        env = build_envelope(_cfg(), batch=[item], tails=[
            {"pane": "%1", "dedupe_key": "h:%1:x", "content_redacted": "ok",
             "redactions_applied": 0},
        ])
        self.assertEqual(env["tier"], 1)  # tails force tier 1
        validate_envelope(env)

    def test_contract_violation_raises(self) -> None:
        with self.assertRaises(ContractViolation):
            validate_envelope({"schema": "wrong", "host": "h", "tier": 0, "batch": []})

    def test_contract_rejects_bad_tier(self) -> None:
        env = build_envelope(_cfg(), batch=[])
        env["tier"] = 7
        with self.assertRaises(ContractViolation):
            validate_envelope(env)

    def test_contract_rejects_unknown_state(self) -> None:
        env = build_envelope(_cfg(), batch=[
            {"kind": "snapshot", "workspaces": [{"name": "w", "panes": [
                {"pane": "%1", "agent_kind": "k", "state": "garbage"},
            ]}]},
        ])
        with self.assertRaises(ContractViolation):
            validate_envelope(env)


class SnapshotReconciliationTest(unittest.TestCase):
    def test_missing_pane_becomes_closed(self) -> None:
        prev = {"workspaces": [{"name": "w", "panes": [
            {"pane": "%1", "agent_kind": "k", "state": "working"},
            {"pane": "%2", "agent_kind": "k", "state": "idle"},
        ]}]}
        curr = {"workspaces": [{"name": "w", "panes": [
            {"pane": "%1", "agent_kind": "k", "state": "working"},
        ]}]}
        from tower_bridge import reconcile_snapshot
        items = reconcile_snapshot(prev, curr)
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["kind"], "pane.closed")
        self.assertEqual(items[0]["workspace"], "w")
        self.assertEqual(items[0]["pane"], "%2")

    def test_no_missing_no_items(self) -> None:
        snap = {"workspaces": [{"name": "w", "panes": [
            {"pane": "%1", "agent_kind": "k", "state": "working"},
        ]}]}
        from tower_bridge import reconcile_snapshot
        self.assertEqual(reconcile_snapshot(snap, snap), [])

    def test_snapshot_envelope_validates(self) -> None:
        snap = {"workspaces": [{"name": "tower", "panes": [
            {"pane_id": "%3", "agent_kind": "claude-code", "agent_status": "working"},
            {"pane_id": "%5", "agent": "codex", "agent_status": "blocked"},
        ]}]}
        env = build_envelope(_cfg(), batch=snapshot_to_batch_items(snap))
        validate_envelope(env)
        ws = env["batch"][0]["workspaces"][0]
        self.assertEqual(len(ws["panes"]), 2)
        # `agent` field is tolerated as a synonym for `agent_kind`.
        self.assertEqual(ws["panes"][1]["agent_kind"], "codex")
        self.assertEqual(ws["panes"][1]["state"], "blocked")

    def test_pane_id_required(self) -> None:
        # Snapshot items without pane_id are dropped.
        snap = {"workspaces": [{"name": "w", "panes": [
            {"agent_kind": "k", "agent_status": "working"},  # no pane_id
            {"pane_id": "%1", "agent_kind": "k", "agent_status": "working"},
        ]}]}
        items = snapshot_to_batch_items(snap)
        ws = items[0]["workspaces"][0]
        self.assertEqual(len(ws["panes"]), 1)
        self.assertEqual(ws["panes"][0]["pane"], "%1")
