<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Trip never exists without an owner (AD-10) or coordinates (AD-8):
     * `user_id`, `canonical_place_name`, `latitude`, `longitude` are all
     * NOT NULL. Status defaults to `active`; delete is a soft delete (AD-5).
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('destination_raw');
            $table->string('canonical_place_name');
            $table->double('latitude');
            $table->double('longitude');
            $table->date('departure_date');
            $table->date('return_date');
            $table->string('status')->default('active'); // active|paused|completed (AD-5)
            $table->softDeletes();                        // AD-5
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
