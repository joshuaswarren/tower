<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runs table — docs/ARCHITECTURE.md §1.2.
     *
     * The producer's run identity is `external_id`; combined with `agent_id`
     * it's the natural key. `state` is the run state machine (see
     * App\Enums\RunState). `duration_ms` is computed at terminal transition.
     * `exit_state` is producer-supplied (success, error:timeout, ...).
     */
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id');
            $table->string('external_id');
            $table->string('title')->nullable();
            $table->string('state');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->string('exit_state')->nullable();
            $table->jsonb('meta');
            $table->timestampsTz();

            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->unique(['agent_id', 'external_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
