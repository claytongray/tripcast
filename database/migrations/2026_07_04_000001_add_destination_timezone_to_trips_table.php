<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The destination's IANA zone (e.g. Europe/London), resolved from coordinates
     * at trip creation (Epic 11, CAP-9). Nullable: a resolution failure leaves it
     * null and callers fall back to the config default for that fetch until it is
     * filled. Consumed by the timezone-aware-send-time feature.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->string('destination_timezone')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('destination_timezone');
        });
    }
};
