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
        Schema::table('tickets', function (Blueprint $table) {
            // Colonne parent_no : clé étrangère vers le ticket parent
            $table->string('parent_no')->nullable()->after('ticket_no');

            // Colonne children_no : tableau JSON des ticket_no enfants
            $table->json('children_no')->nullable()->after('parent_no');

            // Clé étrangère auto-référente
            $table
                ->foreign('parent_no')
                ->references('ticket_no')
                ->on('tickets')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['parent_no']);
            $table->dropColumn(['parent_no', 'children_no']);
        });
    }
};
