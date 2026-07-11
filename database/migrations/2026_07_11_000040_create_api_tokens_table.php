<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API tokens table — docs/ARCHITECTURE.md §1.2 + ADR-0002.
     *
     * Hand-rolled instead of Sanctum: producers are not `users`, and the
     * custom path is small and fully testable. Token format `twr_<base62 40>`;
     * plaintext shown once at mint, only SHA-256 stored. `token_prefix` is
     * the first 12 chars for O(1) lookup; `token_hash` is the full SHA-256.
     *
     * The owner morph is a ULID, not a bigint: tokens may own either an
     * `Agent` (pane-agent) or a `Host` (bridge token) — both ULID-keyed.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('token_prefix', 12);
            $table->string('token_hash', 64)->unique();
            $table->nullableUlidMorphs('owner');
            $table->jsonb('abilities');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index('token_prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
