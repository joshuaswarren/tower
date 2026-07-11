<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowlists table — docs/ARCHITECTURE.md §1.2.
     *
     * `version` is monotonic per agent; the highest version is the active
     * manifest (enforced by the `active` scope on the model). Drift detection
     * is opt-in: an agent with no declared allowlist is never flagged.
     */
    public function up(): void
    {
        Schema::create('allowlists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id');
            $table->integer('version');
            $table->jsonb('manifest');
            $table->timestampTz('declared_at');
            $table->timestampsTz();

            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->unique(['agent_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowlists');
    }
};
