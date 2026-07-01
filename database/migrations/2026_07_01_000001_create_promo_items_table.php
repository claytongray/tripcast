<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed weather-keyed promo catalog (FR-26, AD-18). Epic 8 foundation: the
 * static config catalog becomes admin-manageable rows. `slug` is the stable
 * attribution key joined by `promo_events.promo_slug`, so its unique is a plain
 * column unique that spans soft-deleted rows — a retired item's slug stays
 * reserved and keeps resolving. Selection indexes back the Story 8.2 profile
 * rotation and Featured-window lookup. Adding this table changes no runtime
 * behavior; the digest still selects from `config('tripcast.promo.catalog')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug');                          // stable attribution key
            $table->string('label');
            $table->string('image_url');
            $table->string('url');                           // base URL; provider (8.2) appends Amazon tag
            $table->string('merchant')->default('amazon');   // amazon|other (Story 8.2 link handling)
            $table->string('weather_profile');               // snow|hot|cold-wet|cold|mild|travel-essentials
            $table->boolean('is_active')->default(true);     // reversible admin toggle (Story 8.3)
            $table->date('featured_from')->nullable();
            $table->date('featured_to')->nullable();         // NULL = open-ended pin
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique('slug');                                        // promo_events.promo_slug joins (8.5)
            $table->index(['is_active', 'weather_profile', 'sort_order']); // 8.2 profile rotation
            $table->index(['is_active', 'featured_from', 'featured_to']);  // 8.2 Featured-window lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_items');
    }
};
