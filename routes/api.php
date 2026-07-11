<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AllowlistController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\HerdrIngestController;
use App\Http\Controllers\Api\V1\ReceiptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Tower ingest API (v1) — docs/ARCHITECTURE.md §1.3.
 *
 * Every route below requires the `token` middleware (constant-time SHA-256
 * compare on the `Authorization: Bearer twr_…` header) and the
 * `throttle:ingest` rate limiter keyed by the token id
 * (config('tower.ingest.rate_per_minute')). Per-endpoint abilities are
 * enforced by the `ability:<name>` middleware.
 */
Route::prefix('v1')->group(function (): void {
    // Auth smoke. Any valid token works (no ability check).
    Route::middleware(['token', 'throttle:ingest'])->get('/ping', function (Request $request) {
        $token = $request->attributes->get('tower_token');
        return response()->json([
            'ok' => true,
            'principal' => [
                'type' => $token?->owner_type,
                'id' => $token?->owner_id,
                'token_id' => $token?->id,
                'name' => $token?->name,
            ],
            'abilities' => (array) ($token?->abilities ?? []),
        ]);
    });

    // Ingest endpoints. The `ingest` ability gates the generic /events
    // envelope; the heartbeat and allowlist paths require it too (per the
    // §1.3 table). /herdr requires the more specific `ingest:herdr` ability
    // AND a host-owned token (enforced inside HerdrIngestController).
    Route::middleware(['token', 'throttle:ingest', 'ability:ingest'])->group(function (): void {
        Route::post('/events', [EventController::class, 'store']);
        Route::post('/heartbeat', [HeartbeatController::class, 'store']);
        Route::post('/allowlist', [AllowlistController::class, 'store']);
    });

    Route::middleware(['token', 'throttle:ingest', 'ability:ingest:herdr'])->group(function (): void {
        Route::post('/herdr', [HerdrIngestController::class, 'store']);
    });

    Route::middleware(['token', 'throttle:ingest', 'ability:attest'])->group(function (): void {
        Route::post('/receipts/{receipt}/attest', [ReceiptController::class, 'attestAction']);
    });
});
