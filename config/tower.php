<?php

declare(strict_types=1);

return [

    // Event retention: tower:prune-events deletes events older than this.
    'retention' => [
        'events_days' => (int) env('TOWER_EVENTS_RETENTION_DAYS', 30),
    ],

    // Ingest limits. Batch size and payload cap are fixed guardrails (not env)
    // so a fork cannot accidentally raise them past what the schema promises.
    'ingest' => [
        'max_batch_size' => 100,
        'max_payload_kb' => 256,
        'rate_per_minute' => (int) env('TOWER_INGEST_RATE', 120),
    ],

    // An agent whose last heartbeat is older than this is swept to `offline`
    // by tower:sweep-stale. This is the whole reason Tower replaces stale
    // HEARTBEAT.md age checks: the board never lies green.
    'staleness' => [
        'offline_seconds' => (int) env('TOWER_OFFLINE_AFTER', 180),
    ],

    // Allowlist drift detection. Global kill switch; agents with no declared
    // manifest are never flagged (drift is opt-in by declaring).
    'drift' => [
        'enabled' => (bool) env('TOWER_DRIFT_ENABLED', true),
    ],

    // Public read-only board at `/`. Only `public` workspaces are ever shown.
    'public_board' => [
        'enabled' => (bool) env('TOWER_PUBLIC_BOARD', true),
    ],

    // Demo agents are cut-safe: disabled by default. When false the console
    // and its routes are absent entirely.
    'demo' => [
        'enabled' => (bool) env('TOWER_DEMO_ENABLED', false),
        'max_concurrent' => 3,
        'rate_per_minute_per_ip' => 3,
    ],

];
