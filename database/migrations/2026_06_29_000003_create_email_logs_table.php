<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `email_logs` is the single per-send source of truth and the forecast-history
     * time-series (AD-9). The unique `(trip_id, send_date)` index is the claim-first
     * idempotency authority (AD-3): the send job inserts the row before any work; a
     * duplicate insert fails the constraint and the job aborts as already-claimed.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('send_date');
            $table->string('status')->default('sending'); // sending|sent|failed (AD-3/AD-4)
            $table->timestamp('claimed_at')->nullable();   // lease for stale reclaim (AD-3)
            $table->text('failure_reason')->nullable();
            $table->json('weather_snapshot')->nullable();   // per-send forecast snapshot (AD-9)
            $table->timestamps();

            $table->unique(['trip_id', 'send_date']);  // claim-first idempotency key (AD-3)
            $table->index(['status', 'claimed_at']);   // stale-lease reclaim scans
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
