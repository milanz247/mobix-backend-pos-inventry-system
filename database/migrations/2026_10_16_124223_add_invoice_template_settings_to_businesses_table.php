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
            $table->string('invoice_title', 100)->default('Sales receipt');
            $table->string('invoice_paper_size', 16)->default('a4');
            $table->string('invoice_accent_color', 7)->default('#1e293b');
            $table->boolean('invoice_show_logo')->default(true);
            $table->boolean('invoice_show_business_details')->default(true);
            $table->boolean('invoice_show_customer')->default(true);
            $table->boolean('invoice_show_cashier')->default(true);
            $table->boolean('invoice_show_payment_details')->default(true);
            $table->boolean('invoice_show_tax')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_title',
                'invoice_paper_size',
                'invoice_accent_color',
                'invoice_show_logo',
                'invoice_show_business_details',
                'invoice_show_customer',
                'invoice_show_cashier',
                'invoice_show_payment_details',
                'invoice_show_tax',
            ]);
        });
    }
};
