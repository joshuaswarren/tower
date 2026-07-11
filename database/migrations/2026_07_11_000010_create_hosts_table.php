<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosts table — docs/ARCHITECTURE.md §1.2.
     *
     * Hosts are physical or logical machines that emit ingest. `name` is the
     * human label (claude-a, codex-a, cloud, github-actions); `kind` drives
     * how the bridge/controller treats ingest; `connect_hint` is the
     * copy-pasteable attach text for the board's "ssh into" affordance;
     * `last_seen_at` is bumped by any ingest from this host.
     */
    public function up(): void
    {
        Schema::create('hosts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('kind');
            $table->string('connect_hint')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosts');
    }
};
