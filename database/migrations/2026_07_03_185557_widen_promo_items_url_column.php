<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heal schema drift on `promo_items.url`. The create migration originally
 * declared `string('url')` (255) and was amended in place to 2048 (fecda3c,
 * Epic 8 review) — so any database migrated before that edit kept the 255
 * column while validation allows `max:2048`, and a long pasted Amazon URL
 * (search-result links carry ~700 chars of tracking params) throws
 * SQLSTATE[22001] on insert. Re-declaring the column is a no-op where it is
 * already 2048 and widens it where it drifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_items', function (Blueprint $table) {
            $table->string('url', 2048)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Widening is data-safe; never narrow back on rollback (rows may
        // already hold URLs longer than 255).
    }
};
