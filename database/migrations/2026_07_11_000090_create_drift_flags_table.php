<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drift flags table — docs/ARCHITECTURE.md §1.2.
     *
     * A drift flag opens at most once per `(agent_id, tool, run_id)` —
     * repeats increment `detail.count` rather than spamming the board.
     * `run_id` is nullable because a drift may occur outside a run context.
     */
    public function up(): void
    {
        Schema::create('drift_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id');
            $table->ulid('run_id')->nullable();
            $table->ulid('allowlist_id')->nullable();
            $table->string('tool');
            $table->jsonb('detail');
            $table->string('severity');
            $table->string('status');
            $table->timestampsTz();

            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->foreign('run_id')
                ->references('id')->on('runs')
                ->nullOnDelete();
            $table->foreign('allowlist_id')
                ->references('id')->on('allowlists')
                ->nullOnDelete();
            $table->index('status');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drift_flags');
    }
};
