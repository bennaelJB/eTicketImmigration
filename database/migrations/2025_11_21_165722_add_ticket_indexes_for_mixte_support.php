<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Ajout d’une colonne générée (stockée ou virtuelle)
            $table->string('ticket_prefix', 1)
                ->virtualAs('LEFT(ticket_no, 1)');

            // Index sur la colonne générée
            $table->index('ticket_prefix', 'idx_ticket_prefix');

            // Index composé classique
            $table->index(['ticket_no', 'created_at'], 'idx_ticket_no_created');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_prefix');
            $table->dropIndex('idx_ticket_no_created');
            $table->dropColumn('ticket_prefix');
        });
    }
};
