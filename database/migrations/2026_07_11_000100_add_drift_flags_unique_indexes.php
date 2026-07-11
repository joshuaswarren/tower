<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce the drift-flag uniqueness invariant at the database layer
 * (ARCHITECTURE.md §1.2: "a drift flag is opened at most once per
 * (agent_id, tool, run_id)").
 *
 * Postgres treats NULLs as distinct in a plain unique index, so run-bound
 * and run-less flags need separate partial indexes. This makes the invariant
 * hold even under concurrent queue workers (ADR-0005 does not pin worker
 * concurrency to 1), backing the application-level dedup in DetectDrift.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'create unique index drift_flags_agent_tool_run_unique '
            .'on drift_flags (agent_id, tool, run_id) where run_id is not null'
        );
        DB::statement(
            'create unique index drift_flags_agent_tool_norun_unique '
            .'on drift_flags (agent_id, tool) where run_id is null'
        );
    }

    public function down(): void
    {
        Schema::table('drift_flags', function (): void {
            DB::statement('drop index if exists drift_flags_agent_tool_run_unique');
            DB::statement('drop index if exists drift_flags_agent_tool_norun_unique');
        });
    }
};
