<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_order_items', function (Blueprint $table): void {
            $table->json('private_inventory_snapshot')->nullable();
        });
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->foreign(['sale_id', 'web_cod_order_id', 'business_id'], 'sale_payments_cod_sale_order_fk')
                ->references(['id', 'web_order_id', 'business_id'])->on('sales')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payment_cod_origin_check CHECK (web_cod_order_id IS NULL OR (method = 'cash' AND web_bank_transfer_decision_id IS NULL AND cashier_shift_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('web_order_items')->whereNotNull('private_inventory_snapshot')->exists()) {
            throw new RuntimeException('Cannot remove accepted inventory allocation history.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sale_payments DROP CONSTRAINT sale_payment_cod_origin_check');
        }
        Schema::table('sale_payments', fn (Blueprint $table) => $table->dropForeign('sale_payments_cod_sale_order_fk'));
        Schema::table('web_order_items', fn (Blueprint $table) => $table->dropColumn('private_inventory_snapshot'));
    }
};
