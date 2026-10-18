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
        Schema::table('businesses', function (Blueprint $table): void {
            $table->boolean('pos_show_customer_name')->default(true)->after('pos_show_customer_details');
            $table->boolean('pos_show_customer_phone')->default(true)->after('pos_show_customer_name');
            $table->boolean('pos_show_customer_email')->default(true)->after('pos_show_customer_phone');
            $table->boolean('pos_show_sale_notes')->default(true)->after('pos_show_customer_email');
        });

        DB::table('businesses')
            ->where('pos_show_customer_details', false)
            ->update([
                'pos_show_customer_name' => false,
                'pos_show_customer_phone' => false,
                'pos_show_customer_email' => false,
                'pos_show_sale_notes' => false,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'pos_show_customer_name',
                'pos_show_customer_phone',
                'pos_show_customer_email',
                'pos_show_sale_notes',
            ]);
        });
    }
};
