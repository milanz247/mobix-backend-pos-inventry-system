<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_order_offers', fn (Blueprint $table) => $table->dropForeign('web_order_offers_area_fk'));
        Schema::table('web_order_offers', function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_area_id')->nullable()->change();
            $table->string('delivery_area_code_snapshot', 80)->nullable()->change();
            $table->string('delivery_area_name_snapshot', 150)->nullable()->change();
            $table->string('fulfillment_mode', 24)->nullable()->index();
            $table->string('pickup_location_code_snapshot', 80)->nullable();
            $table->string('pickup_location_name_snapshot', 150)->nullable();
        });
        Schema::table('web_order_offers', function (Blueprint $table): void {
            $table->foreign(['delivery_area_id', 'business_id'], 'web_order_offers_area_fk')
                ->references(['id', 'business_id'])->on('web_delivery_areas')->restrictOnDelete();
        });
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->string('fulfillment_mode', 24)->nullable()->index();
            $table->string('pickup_location_code_snapshot', 80)->nullable();
            $table->string('pickup_location_name_snapshot', 150)->nullable();
            $table->unique(['id', 'fulfillment_mode', 'business_id'], 'web_orders_id_mode_business_unique');
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_order_id')->nullable();
            $table->unique(['business_id', 'web_order_id'], 'sales_business_web_order_unique');
            $table->unique(['id', 'web_order_id', 'business_id'], 'sales_id_web_order_business_unique');
            $table->unique(['id', 'inventory_operation_id', 'business_id'], 'sales_id_inventory_business_unique');
            $table->foreign(['web_order_id', 'business_id'], 'sales_web_order_business_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
        });
        Schema::table('web_bank_transfer_decisions', function (Blueprint $table): void {
            $table->unique(['id', 'business_id'], 'web_bank_decisions_id_business_unique');
        });
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_bank_transfer_decision_id')->nullable()
                ->unique('sale_payments_web_bank_decision_unique');
            $table->foreign(['web_bank_transfer_decision_id', 'business_id'], 'sale_payments_bank_decision_business_fk')
                ->references(['id', 'business_id'])->on('web_bank_transfer_decisions')->restrictOnDelete();
        });
        Schema::create('web_order_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('inventory_operation_id');
            $table->string('handover_mode', 24);
            $table->string('handover_status', 24);
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('handover_actor_id');
            $table->timestamp('handover_at');
            $table->string('evidence_reference_snapshot', 180)->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_fulfillments_id_business_unique');
            $table->unique(['business_id', 'web_order_id'], 'web_fulfillments_order_unique');
            $table->unique(['business_id', 'sale_id'], 'web_fulfillments_sale_unique');
            $table->unique(['business_id', 'inventory_operation_id'], 'web_fulfillments_inventory_unique');
            $table->unique(['business_id', 'operation_key'], 'web_fulfillments_operation_unique');
            $table->index(['business_id', 'handover_at'], 'web_fulfillments_handover_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_fulfillments_order_business_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign(['web_order_id', 'handover_mode', 'business_id'], 'web_fulfillments_mode_business_fk')
                ->references(['id', 'fulfillment_mode', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign(['sale_id', 'web_order_id', 'business_id'], 'web_fulfillments_sale_order_business_fk')
                ->references(['id', 'web_order_id', 'business_id'])->on('sales')->restrictOnDelete();
            $table->foreign(['sale_id', 'inventory_operation_id', 'business_id'], 'web_fulfillments_sale_inventory_business_fk')
                ->references(['id', 'inventory_operation_id', 'business_id'])->on('sales')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'web_fulfillments_inventory_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
            $table->foreign('handover_actor_id', 'web_fulfillments_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_web_source_check CHECK ((source = 'ecommerce' AND web_order_id IS NOT NULL) OR (source <> 'ecommerce' AND web_order_id IS NULL))");
            DB::statement("ALTER TABLE web_order_fulfillments ADD CONSTRAINT web_fulfillment_mode_check CHECK (handover_mode IN ('shop_pickup', 'home_delivery') AND handover_status = 'posted')");
            DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payment_bank_origin_check CHECK (web_bank_transfer_decision_id IS NULL OR method = 'bank_transfer')");
        }
    }

    public function down(): void
    {
        if (DB::table('web_order_fulfillments')->exists() || DB::table('sales')->whereNotNull('web_order_id')->exists()
            || DB::table('sale_payments')->whereNotNull('web_bank_transfer_decision_id')->exists()
            || DB::table('web_order_offers')->where('fulfillment_mode', 'shop_pickup')->exists()
            || DB::table('web_orders')->where('fulfillment_mode', 'shop_pickup')->exists()) {
            throw new RuntimeException('Cannot roll back web sale bridge history or accepted pickup terms.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_web_source_check');
            DB::statement('ALTER TABLE sale_payments DROP CONSTRAINT sale_payment_bank_origin_check');
        }
        Schema::dropIfExists('web_order_fulfillments');
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropForeign('sale_payments_bank_decision_business_fk');
            $table->dropUnique('sale_payments_web_bank_decision_unique');
            $table->dropColumn('web_bank_transfer_decision_id');
        });
        Schema::table('web_bank_transfer_decisions', fn (Blueprint $table) => $table->dropUnique('web_bank_decisions_id_business_unique'));
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign('sales_web_order_business_fk');
            $table->dropUnique('sales_id_web_order_business_unique');
            $table->dropUnique('sales_id_inventory_business_unique');
            $table->dropUnique('sales_business_web_order_unique');
            $table->dropColumn('web_order_id');
        });
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->dropUnique('web_orders_id_mode_business_unique');
            $table->dropColumn(['fulfillment_mode', 'pickup_location_code_snapshot', 'pickup_location_name_snapshot']);
        });
        Schema::table('web_order_offers', fn (Blueprint $table) => $table->dropForeign('web_order_offers_area_fk'));
        Schema::table('web_order_offers', function (Blueprint $table): void {
            $table->dropColumn(['fulfillment_mode', 'pickup_location_code_snapshot', 'pickup_location_name_snapshot']);
            $table->unsignedBigInteger('delivery_area_id')->nullable(false)->change();
            $table->string('delivery_area_code_snapshot', 80)->nullable(false)->change();
            $table->string('delivery_area_name_snapshot', 150)->nullable(false)->change();
        });
        Schema::table('web_order_offers', function (Blueprint $table): void {
            $table->foreign(['delivery_area_id', 'business_id'], 'web_order_offers_area_fk')
                ->references(['id', 'business_id'])->on('web_delivery_areas')->restrictOnDelete();
        });
    }
};
