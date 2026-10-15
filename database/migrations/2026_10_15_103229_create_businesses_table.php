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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('setup_key', 16)->default('initial')->unique();
            $table->string('name', 150);
            $table->string('business_type', 32);
            $table->string('email', 254)->nullable();
            $table->string('phone', 32);
            $table->text('address');
            $table->string('city', 100);
            $table->string('country', 100)->default('Sri Lanka');
            $table->string('currency', 3)->default('LKR');
            $table->string('logo_path')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
