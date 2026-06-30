<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate engagement events (FR-18, AD-18) — tripcast's own measure (SM-4).
 * One idempotent row per (trip_id, send_date, promo_slug, event): impression at
 * send, click at follow. The promo text/selection is config-derivable, not
 * stored beyond these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('send_date');
            $table->string('promo_slug');
            $table->string('event'); // impression|click (AD-18)
            $table->timestamps();

            // Idempotency key (AD-18): a re-click / mail-client prefetch can't double-log.
            $table->unique(['trip_id', 'send_date', 'promo_slug', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_events');
    }
};
