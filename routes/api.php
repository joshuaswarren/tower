<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Tower ingest API (v1). Lane A owns this file: it wires the endpoints in
// docs/ARCHITECTURE.md §1.3 (events, heartbeat, allowlist, receipts/attest,
// herdr, ping) behind the `token` middleware + `throttle:ingest`.
Route::prefix('v1')->group(function (): void {
    //
});
