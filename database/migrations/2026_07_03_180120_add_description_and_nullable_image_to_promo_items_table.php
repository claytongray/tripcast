<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog UX (2026-07-03 spec): the admin form no longer collects images, so
 * `image_url` relaxes to NULL (column + data kept for later reuse); the new
 * `description` is the optional one-line editorial copy shown under the label
 * link in the digest promo unit. 500 matches the validation cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_items', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->change();
            $table->string('description', 500)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('promo_items', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable(false)->change();
            $table->dropColumn('description');
        });
    }
};
