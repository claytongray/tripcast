<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Passwordless by design (AD-6): no password, remember-token, or reset
     * columns exist anywhere. `email` carries an explicit case-insensitive
     * collation so the unique index and account matching (AD-3, AD-10) treat
     * `Foo@x.com` and `foo@x.com` as the same address regardless of the DB
     * default collation.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->collation('utf8mb4_0900_ai_ci')->unique();
            $table->timestamp('email_verified_at')->nullable(); // set on first magic-link consume (AD-6); gates sends (AD-11)
            $table->string('plan')->default('free');           // free|ad_free (AD-19)
            $table->string('timezone')->default('America/New_York'); // collected; unused for sends in v1 (AD-7)
            $table->boolean('is_admin')->default(false);        // AD-12
            $table->boolean('email_opted_out')->default(false); // AD-13
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
