<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workspaces table — docs/ARCHITECTURE.md §1.2.
     *
     * Workspaces are the "single sanitization switch" of the public board.
     * `visibility = public` is required for a workspace to ever be rendered
     * on the public board or broadcast on `public.board`. Logical workspaces
     * (e.g. an `acme` org) may not have a host; herdr workspaces do.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('host_id')->nullable();
            $table->string('name');
            $table->string('visibility');
            $table->timestampsTz();

            $table->foreign('host_id')
                ->references('id')->on('hosts')
                ->nullOnDelete();
            $table->unique(['host_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
