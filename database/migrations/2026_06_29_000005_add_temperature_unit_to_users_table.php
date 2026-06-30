<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account-level temperature unit preference. The digest renders a single
     * unit (this one), captured in the trip-setup form and defaulting to
     * Fahrenheit. The forecast snapshot still carries both units (AD-7); only
     * the render is single-unit now.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('temperature_unit')->default('fahrenheit')->after('email_opted_out'); // fahrenheit|celsius
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temperature_unit');
        });
    }
};
