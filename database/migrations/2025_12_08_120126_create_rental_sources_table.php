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
        Schema::create('rental_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_url')->unique();
            $table->enum('source_type', ['AGENCY', 'PRIVATE']);
            $table->string('name_or_title');
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('property_type')->nullable();
            $table->string('city');
            $table->string('district')->nullable();
            $table->boolean('is_qualified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_sources');
    }
};
