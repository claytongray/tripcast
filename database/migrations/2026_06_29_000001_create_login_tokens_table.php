<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Single-use magic-link login tokens (AD-6). Only the SHA-256 hash of the
     * token is stored here; the raw token lives in the emailed URL and — for
     * same-browser resend reuse (AD-6, 2026-07-01) — transiently in the
     * server-side session. This table stays hash-only. A row is spent by setting
     * `consumed_at`. Expired/consumed rows are pruned on a schedule.
     */
    public function up(): void
    {
        Schema::create('login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Pruning queries select on these.
            $table->index(['user_id', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_tokens');
    }
};
