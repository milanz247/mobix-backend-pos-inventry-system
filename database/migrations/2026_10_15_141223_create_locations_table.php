<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 32);
            $table->string('type', 32);
            $table->string('phone', 32);
            $table->string('email', 254)->nullable();
            $table->text('address');
            $table->string('city', 100);
            $table->boolean('is_main')->default(false);
            $table->boolean('main_slot')->nullable()->storedAs('IF(`is_main` = 1, 1, NULL)');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'main_slot']);
            $table->index(['business_id', 'status', 'name']);
        });

        $now = now();

        DB::table('businesses')->orderBy('id')->each(function (object $business) use ($now): void {
            DB::table('locations')->insert([
                'business_id' => $business->id,
                'name' => $business->name,
                'code' => 'MAIN',
                'type' => 'shop',
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'city' => $business->city,
                'is_main' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
