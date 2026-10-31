<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('held_carts', function (Blueprint $table) {
            $table->unsignedBigInteger('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('held_carts', fn (Blueprint $table) => $table->dropColumn(['claimed_by', 'claimed_at']));
    }
};
