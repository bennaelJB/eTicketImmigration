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
        Schema::create('passenger_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
            $table->string('last_name');
            $table->string('first_name');
            $table->date('date_of_birth');
            $table->enum('sex', ['M', 'F']);
            $table->string('birth_place');
            $table->string('nationality')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('carrier_number')->nullable();
            $table->foreignId('port_of_entry')->constrained('ports');
            $table->enum('travel_purpose', ['business', 'recreation', 'other'])->nullable();
            $table->string('visa_number')->nullable();
            $table->date('visa_issued_at')->nullable();
            $table->string('residence_street')->nullable();
            $table->string('residence_city')->nullable();
            $table->string('residence_country')->nullable();
            $table->string('haiti_street')->nullable();
            $table->string('haiti_city')->nullable();
            $table->string('haiti_phone')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passenger_forms');
    }
};
