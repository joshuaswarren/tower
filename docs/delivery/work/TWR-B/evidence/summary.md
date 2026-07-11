# TWR-B evidence — realtime board (channels, Livewire islands, public sanitization)

## Result
`DB_DATABASE=tower_test_b ./vendor/bin/pest` — **46 passed, 189 assertions** (full lane-b suite incl. spine).
Board + broadcast + auth subset: **23 passed, 78 assertions**.

## Proven
- Authed `/board` renders agents across all five statuses; `attention` island lists the oldest-blocked agent first.
- Public `/` renders zero private-workspace identifiers while `/board` shows them (query-level `visibility=public` filtering); `TOWER_PUBLIC_BOARD=false` redirects `/` to login; a guest hitting `/board` is redirected to login.
- Run detail renders the event timeline in chronological order; attested receipts render a linked chip (`data-receipt-status="attested"` + URL), unattested render the stub chip with no link.
- Broadcast contract: `RunUpdated` for a private workspace broadcasts only on `private-fleet.board`; for a public workspace on `private-fleet.board` + `public.board`. All four events use the frozen `broadcastAs` names. `ReceiptUpdated` payload carries only `{receipt_id, run_id, status, workspace_id}` — no `url`, no private fields (leak test).
- Channel auth: `fleet.board` closure authorizes a persisted user and denies a non-persisted one; `public.board` is open. `/broadcasting/auth` denies guests via `auth` middleware (driver-independent) and authorizes an authenticated user.

## Bugs fixed during completion (in lane B code)
- `routes/web.php`: `/runs/{run}` used a hand-written uppercase-Crockford constraint that 404'd on Laravel's lowercase-stored ULIDs. Replaced with the framework's `->whereUlid('run')`.
- `bootstrap/app.php`: moved channel registration to `withBroadcasting(..., ['middleware' => ['web','auth']])` so private-channel subscription requires an authenticated session at the HTTP boundary, independent of the broadcast driver (the `null` test driver otherwise no-ops channel auth). Exactly one `/broadcasting/auth` route, middleware `['web','auth']`.

## Deferred to integration verification (needs the running app)
- Responsive visual pass (375/768/1024/1440), Reverb/Echo live round-trip, Vite asset build — captured in the final verification phase, not unit-testable here.
