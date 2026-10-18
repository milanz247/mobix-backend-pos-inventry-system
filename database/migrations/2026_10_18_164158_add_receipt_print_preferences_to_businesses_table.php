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
        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('invoice_thermal_width')->default(80)->after('invoice_paper_size');
            $table->boolean('invoice_show_product_codes')->default(true)->after('invoice_show_tax');
            $table->boolean('invoice_show_line_discounts')->default(true)->after('invoice_show_product_codes');
            $table->boolean('pos_auto_print_receipt')->default(false)->after('pos_show_receipt_after_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_thermal_width',
                'invoice_show_product_codes',
                'invoice_show_line_discounts',
                'pos_auto_print_receipt',
            ]);
        });
    }
};
