<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_email_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient');       // owner | admin
            $table->string('recipient_email');
            $table->string('status');          // sent | failed
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'created_at']); // per-trip trail, newest first
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_email_sends');
    }
};
