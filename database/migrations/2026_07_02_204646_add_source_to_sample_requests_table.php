<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a sample send originated: 'landing' is the public acquisition
     * funnel; 'dashboard' is a signed-in user's self-send. The default
     * backfills every pre-existing row as landing — historically accurate,
     * since the dashboard card records rows only from this migration onward.
     */
    public function up(): void
    {
        Schema::table('sample_requests', function (Blueprint $table) {
            $table->string('source', 20)->default('landing')->after('destination')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sample_requests', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
