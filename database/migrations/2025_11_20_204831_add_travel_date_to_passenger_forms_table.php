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
            // Ajout de la colonne travel_date
            // Elle est placée ici dans la section 'Informations de voyage'
            $table->date('travel_date')->after('carrier_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passenger_forms', function (Blueprint $table) {
            // Commande pour supprimer la colonne en cas d'annulation de la migration
            $table->dropColumn('travel_date');
        });
    }
};
