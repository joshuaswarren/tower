<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts table — docs/ARCHITECTURE.md §1.2.
     *
     * Every `done` transition auto-creates exactly one `unattested` stub.
     * Attestation is immutable (`unattested -> attested` only) and is
     * gated on either a User session or a token with the `attest` ability.
     * The `stub` jsonb is frozen at creation: it captures the run context
     * so the board can render the receipt honestly even if the run is gone.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->ulid('agent_id');
            $table->string('status');
            $table->string('kind')->nullable();
            $table->string('url')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('stub');
            $table->nullableUlidMorphs('attested_by');
            $table->timestampTz('attested_at')->nullable();
            $table->timestampsTz();

            $table->foreign('run_id')
                ->references('id')->on('runs')
                ->cascadeOnDelete();
            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
