<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events table — docs/ARCHITECTURE.md §1.2 + ADR-0003.
     *
     * Append-only hot table. `id` is bigint auto-increment (NOT a ULID) so
     * BRIN + `(agent_id, id desc)` keep recent reads cheap. The partial
     * unique on `dedupe_key` (only when NOT NULL) gives producer idempotency
     * without blocking legitimate batches that omit dedupe keys.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->ulid('agent_id');
            $table->ulid('run_id')->nullable();
            $table->string('type');
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->jsonb('payload');
            $table->string('source');
            $table->string('dedupe_key')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->foreign('run_id')
                ->references('id')->on('runs')
                ->nullOnDelete();
            $table->index(['agent_id', 'id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index('run_id');
        });

        // BRIN is the right index for an append-only, monotonically-growing
        // timestamp — tiny on disk, scans the recent range fast.
        DB::statement('create index events_received_at_brin on events using brin (received_at)');

        // Producer idempotency: a repeated dedupe_key is rejected at insert;
        // null dedupe_keys are fine (not all producers can supply one).
        DB::statement(
            'create unique index events_dedupe_key_unique on events (dedupe_key) '
            .'where dedupe_key is not null'
        );
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_received_at_brin');
            $table->dropIndex('events_dedupe_key_unique');
        });
        Schema::dropIfExists('events');
    }
};
