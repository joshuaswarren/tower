# Broadcast channels & event contract (frozen — TWR-003)

Every board-relevant mutation broadcasts on exactly the channels/names below.
Any code that broadcasts or subscribes MUST use these strings verbatim.

## Channels (`routes/channels.php`)

| Channel | Visibility | Authorization | Carries |
|---|---|---|---|
| `fleet.board` | private | any authenticated user (single-tenant = the admin) | every board event, full payload |
| `public.board` | public | none | only events whose workspace is `public`, slimmed payload (no `payload`, no private URLs) |
| `demo.run.{runId}` | public | none | AI SDK stream chunks for a dispatched demo run |

## Broadcast event classes (`App\Events\Board\*`, all `ShouldBroadcast`, queued on `ingest` unless noted)

| Class | `broadcastAs` | Channels | Queued | Payload (`broadcastWith`) |
|---|---|---|---|---|
| `AgentStatusChanged` | `agent.status_changed` | `fleet.board`; `public.board` if workspace public | yes | `{agent_id, status, workspace_id}` |
| `RunUpdated` | `run.updated` | `fleet.board`; `public.board` if workspace public | yes | `{run_id, agent_id, state, workspace_id}` (public variant omits `payload`) |
| `DriftFlagRaised` | `drift.raised` | `fleet.board`; `public.board` if workspace public | yes | `{drift_flag_id, agent_id, severity, workspace_id}` |
| `ReceiptUpdated` | `receipt.updated` | `fleet.board`; `public.board` if workspace public | yes | `{receipt_id, run_id, status, workspace_id}` (public variant omits `url` for private runs) |
| `DemoOutputStreamed` | `demo.chunk` | `demo.run.{runId}` | no (streamed inline from `RunDemoAgent` for latency) | `{run_id, seq, chunk}` |

## Client bridge (`resources/js/board.js`)

Echo subscribes to `fleet.board` (authed board) or `public.board` (public `/`),
maps each `broadcastAs` name to the island(s) it touches, and calls
`$wire.$island(name).refresh()` throttled to 500ms per island. Pushed payloads
are a refresh SIGNAL only — never rendered client-side (ADR-0004).

| Broadcast name | Islands refreshed |
|---|---|
| `agent.status_changed` | `grid`, `attention` |
| `run.updated` | `grid`, `feed` |
| `drift.raised` | `attention`, `feed` |
| `receipt.updated` | `receipts`, `feed` |
| `demo.chunk` | (DemoConsole component, direct) |
