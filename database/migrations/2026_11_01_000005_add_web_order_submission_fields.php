<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_order_offer_id')->nullable();
            $table->char('guest_principal_hash', 64)->nullable();
            $table->char('accepted_terms_digest', 64)->nullable();
            $table->char('offer_reference_snapshot', 26)->nullable();
            $table->string('delivery_area_code_snapshot', 80)->nullable();
            $table->string('delivery_area_name_snapshot', 150)->nullable();
            $table->string('location_code_snapshot', 80)->nullable();
            $table->string('location_name_snapshot', 150)->nullable();
            $table->unique('web_order_offer_id', 'web_orders_offer_unique');
            $table->index(['business_id', 'guest_principal_hash'], 'web_orders_guest_principal_idx');
            $table->foreign(['web_order_offer_id', 'business_id'], 'web_orders_offer_business_fk')
                ->references(['id', 'business_id'])->on('web_order_offers')->restrictOnDelete();
        });

        Schema::table('web_order_items', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('source_offer_line_number')->nullable();
            $table->json('included_device_conditions_snapshot')->nullable();
        });

        Schema::create('web_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->string('method', 24);
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->decimal('amount_due', 16, 2);
            $table->timestamps();
            $table->unique('web_order_id', 'web_order_payments_order_unique');
            $table->index(['business_id', 'status'], 'web_order_payments_status_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_order_payments_order_business_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE web_order_payments ADD CONSTRAINT web_order_payment_nonnegative CHECK (amount_due >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_payments');
        Schema::table('web_order_items', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'source_offer_line_number', 'included_device_conditions_snapshot']);
        });
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->dropForeign('web_orders_offer_business_fk');
            $table->dropUnique('web_orders_offer_unique');
            $table->dropIndex('web_orders_guest_principal_idx');
            $table->dropColumn(['web_order_offer_id', 'guest_principal_hash', 'accepted_terms_digest', 'offer_reference_snapshot',
                'delivery_area_code_snapshot', 'delivery_area_name_snapshot', 'location_code_snapshot', 'location_name_snapshot']);
        });
    }
};
