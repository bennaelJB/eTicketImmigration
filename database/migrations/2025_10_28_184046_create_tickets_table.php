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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique()->nullable();
            $table->enum('passenger_type', ['haitian', 'foreigner']);
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->enum('status', [
                'draft',
                'pending',
                'accepted_arrival',
                'rejected_arrival',
                'accepted_departure',
                'rejected_departure'
            ]);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
