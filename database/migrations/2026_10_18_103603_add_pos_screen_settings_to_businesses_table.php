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
            $table->boolean('pos_show_sale_source')->default(true)->after('invoice_show_tax');
            $table->boolean('pos_show_customer_details')->default(true)->after('pos_show_sale_source');
            $table->boolean('pos_show_pay_exact')->default(true)->after('pos_show_customer_details');
            $table->boolean('pos_show_date_time')->default(true)->after('pos_show_pay_exact');
            $table->boolean('pos_show_sales_history')->default(true)->after('pos_show_date_time');
            $table->boolean('pos_show_category_filter')->default(true)->after('pos_show_sales_history');
            $table->boolean('pos_show_brand_filter')->default(true)->after('pos_show_category_filter');
            $table->boolean('pos_show_location_filter')->default(true)->after('pos_show_brand_filter');
            $table->boolean('pos_show_receipt_after_sale')->default(true)->after('pos_show_location_filter');
            $table->unsignedSmallInteger('pos_products_per_page')->default(24)->after('pos_show_receipt_after_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'pos_show_sale_source',
                'pos_show_customer_details',
                'pos_show_pay_exact',
                'pos_show_date_time',
                'pos_show_sales_history',
                'pos_show_category_filter',
                'pos_show_brand_filter',
                'pos_show_location_filter',
                'pos_show_receipt_after_sale',
                'pos_products_per_page',
            ]);
        });
    }
};
