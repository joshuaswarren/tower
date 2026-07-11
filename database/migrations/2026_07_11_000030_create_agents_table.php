<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents table — docs/ARCHITECTURE.md §1.2.
     *
     * `host_id` is denormalized for board grouping (so the Livewire board
     * doesn't have to join through workspaces). `status` is only written by
     * ApplyRunTransition / heartbeat handler / staleness sweep — never by
     * controllers directly.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('host_id')->nullable();
            $table->string('name');
            $table->string('kind');
            $table->string('status');
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->jsonb('meta');
            $table->timestampsTz();

            $table->foreign('workspace_id')
                ->references('id')->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('host_id')
                ->references('id')->on('hosts')
                ->nullOnDelete();
            $table->index('status');
            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
