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
        Schema::create('location_user', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['location_id', 'user_id']);
        });

        $now = now();

        DB::table('locations')
            ->join('businesses', 'businesses.id', '=', 'locations.business_id')
            ->select(['locations.id as location_id', 'businesses.owner_id'])
            ->orderBy('locations.id')
            ->each(function (object $location) use ($now): void {
                DB::table('location_user')->insertOrIgnore([
                    'location_id' => $location->location_id,
                    'user_id' => $location->owner_id,
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
        Schema::dropIfExists('location_user');
    }
};
