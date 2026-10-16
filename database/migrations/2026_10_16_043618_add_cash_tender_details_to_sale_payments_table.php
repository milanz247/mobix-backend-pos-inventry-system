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
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->decimal('cash_tendered', 14, 2)->nullable()->after('amount');
            $table->decimal('change_given', 14, 2)->default(0)->after('cash_tendered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropColumn(['cash_tendered', 'change_given']);
        });
    }
};
