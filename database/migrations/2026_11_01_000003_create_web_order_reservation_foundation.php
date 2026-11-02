<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('web_cart_id')->nullable();
            $table->unsignedBigInteger('web_quote_id')->nullable();
            $table->char('reference', 26);
            $table->uuid('submission_key');
            $table->char('payload_hash', 64);
            $table->char('guest_access_token_hash', 64)->nullable();
            $table->string('status', 32);
            $table->string('payment_method', 24)->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('delivery_fee', 16, 2)->default(0);
            $table->decimal('total', 16, 2);
            $table->json('contact_snapshot')->nullable();
            $table->json('address_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_orders_id_business_unique');
            $table->unique(['business_id', 'reference'], 'web_orders_business_reference_unique');
            $table->unique(['business_id', 'submission_key'], 'web_orders_business_submission_unique');
            $table->index(['business_id', 'status', 'reservation_expires_at'], 'web_orders_status_expiry_idx');
            $table->foreign(['location_id', 'business_id'], 'web_orders_location_business_fk')->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['web_cart_id', 'business_id'], 'web_orders_cart_business_fk')->references(['id', 'business_id'])->on('web_carts')->restrictOnDelete();
            $table->foreign(['web_quote_id', 'business_id'], 'web_orders_quote_business_fk')->references(['id', 'business_id'])->on('web_quotes')->restrictOnDelete();
        });

        Schema::create('web_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedSmallInteger('line_number');
            $table->string('product_name_snapshot', 180);
            $table->string('product_slug_snapshot', 180);
            $table->string('variant_name_snapshot', 150);
            $table->string('condition_snapshot', 24)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_order_items_id_business_unique');
            $table->unique(['id', 'web_order_id', 'business_id'], 'web_order_items_order_scope_unique');
            $table->unique(['web_order_id', 'line_number'], 'web_order_items_line_unique');
            $table->foreign(['web_order_id', 'business_id'], 'web_order_items_order_business_fk')->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign(['product_id', 'business_id'], 'web_order_items_product_business_fk')->references(['id', 'business_id'])->on('products')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'web_order_items_variant_business_fk')->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('web_order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 24);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->text('customer_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'web_order_id', 'created_at'], 'web_order_history_order_time_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_order_history_order_business_fk')->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
        });

        Schema::create('web_order_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('web_order_item_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->unsignedBigInteger('active_device_id')->nullable()->unique('web_reservations_active_device_unique');
            $table->string('reservation_line_key', 80);
            $table->decimal('quantity', 14, 3);
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('transitioned_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_reservations_id_business_unique');
            $table->unique(['web_order_item_id', 'reservation_line_key'], 'web_reservations_item_line_unique');
            $table->index(['business_id', 'location_id', 'component_variant_id', 'status'], 'web_reservations_pool_idx');
            $table->index(['business_id', 'web_order_id', 'status'], 'web_reservations_order_status_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_reservations_order_business_fk')->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign(['web_order_item_id', 'web_order_id', 'business_id'], 'web_reservations_item_order_business_fk')->references(['id', 'web_order_id', 'business_id'])->on('web_order_items')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'web_reservations_location_business_fk')->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['component_variant_id', 'business_id'], 'web_reservations_variant_business_fk')->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'web_reservations_device_business_fk')->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
            $table->foreign(['active_device_id', 'business_id'], 'web_reservations_active_device_business_fk')->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE web_order_reservations ADD CONSTRAINT web_reservations_positive_qty CHECK (quantity > 0)');
            DB::statement("ALTER TABLE web_order_reservations ADD CONSTRAINT web_reservations_active_device_check CHECK ((status = 'active' AND ((serialized_device_id IS NULL AND active_device_id IS NULL) OR (serialized_device_id IS NOT NULL AND active_device_id = serialized_device_id AND quantity = 1))) OR (status <> 'active' AND active_device_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_reservations');
        Schema::dropIfExists('web_order_status_histories');
        Schema::dropIfExists('web_order_items');
        Schema::dropIfExists('web_orders');
    }
};
