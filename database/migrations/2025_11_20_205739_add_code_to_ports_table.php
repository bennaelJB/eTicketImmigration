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
        Schema::table('ports', function (Blueprint $table) {
            // Ajout de la colonne 'code' pour stocker, par exemple, le code IATA ou ICAO du port.
            // On le définit comme unique pour éviter les doublons et non nul.
            $table->string('code', 10)->unique()->after('id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            // Commande pour supprimer la colonne en cas d'annulation de la migration
            $table->dropColumn('code');
        });
    }
};
