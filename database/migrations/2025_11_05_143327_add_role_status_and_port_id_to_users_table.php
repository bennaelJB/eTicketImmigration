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
        Schema::table('users', function (Blueprint $table) {
            // 1. Ajouter la colonne 'role'
            $table->enum('role', ['admin', 'supervisor', 'agent'])->default('agent')->after('password');

            // 2. Ajouter la colonne 'status'
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active')->after('role');

            // 3. Ajouter la clé étrangère 'port_id'
            // Assurez-vous que la table 'ports' existe AVANT d'exécuter cette migration.
            // On le met nullable car les utilisateurs existants n'auront pas de port_id initialement.
            $table->foreignId('port_id')
                  ->nullable()
                  ->constrained('ports')
                  ->onDelete('set null')
                  ->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1. Supprimer la clé étrangère (nécessaire avant de supprimer la colonne)
            $table->dropForeign(['port_id']);

            // 2. Supprimer la colonne 'port_id'
            $table->dropColumn('port_id');

            // 3. Supprimer les colonnes 'role' et 'status'
            $table->dropColumn('status');
            $table->dropColumn('role');
        });
    }
};
