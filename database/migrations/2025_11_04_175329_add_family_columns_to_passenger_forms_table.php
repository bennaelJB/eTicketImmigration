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
        Schema::table('passenger_forms', function (Blueprint $table) {
            $table->integer('number_of_family_members')->default(0)->after('passport_number'); // 0 = voyageant seul
            $table->json('family_members')->nullable()->after('number_of_family_members'); // Array de membres
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passenger_forms', function (Blueprint $table) {
            $table->dropColumn(['number_of_family_members', 'family_members']);
        });
    }
};
