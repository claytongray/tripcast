<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `feedback` is the one-tap digest reaction (FR-8, AD-9): one row per
     * (trip_id, send_date), upserted last-reaction-wins. It joins `email_logs`
     * on the same key and survives the forecast-retention purge and a trip
     * soft-delete — the metric/audit trail. The cascade fires only on a true
     * hard delete (mirrors `email_logs`).
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('send_date');
            $table->string('reaction'); // helped|not_helpful
            $table->timestamps();

            $table->unique(['trip_id', 'send_date']); // last-reaction-wins upsert key (AD-9)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
