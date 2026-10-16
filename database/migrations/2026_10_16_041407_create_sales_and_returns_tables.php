<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->string('sale_number', 40)->nullable();
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->string('status', 24)->default('completed');
            $table->string('payment_status', 24)->default('unpaid');
            $table->unsignedBigInteger('inventory_operation_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'sales_id_business_unique');
            $table->unique(['business_id', 'sale_number'], 'sales_business_number_unique');
            $table->unique(['business_id', 'operation_key'], 'sales_business_operation_unique');
            $table->unique(['business_id', 'inventory_operation_id'], 'sales_business_inventory_op_unique');
            $table->index(['business_id', 'location_id', 'posted_at'], 'sales_business_location_posted_idx');
            $table->index(['business_id', 'payment_status', 'posted_at'], 'sales_business_payment_posted_idx');
            $table->foreign(['location_id', 'business_id'], 'sales_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'sales_inventory_op_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->string('product_name_snapshot', 180);
            $table->string('variant_name_snapshot', 120);
            $table->string('sku_snapshot', 80);
            $table->string('barcode_snapshot', 100)->nullable();
            $table->string('tracking_mode', 24);
            $table->boolean('is_combo')->default(false);
            $table->string('imei_snapshot', 32)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('cost_snapshot', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'sale_lines_id_business_unique');
            $table->unique(['business_id', 'sale_id', 'serialized_device_id'], 'sale_lines_sale_device_unique');
            $table->index(['business_id', 'product_variant_id']);
            $table->foreign(['sale_id', 'business_id'], 'sale_lines_sale_business_fk')
                ->references(['id', 'business_id'])->on('sales')->cascadeOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'sale_lines_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'sale_lines_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        Schema::create('sale_line_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_line_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->string('sku_snapshot', 80);
            $table->string('name_snapshot', 180);
            $table->string('imei_snapshot', 32)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost_snapshot', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'sale_components_id_business_unique');
            $table->unique(['business_id', 'sale_line_id', 'serialized_device_id'], 'sale_components_line_device_unique');
            $table->index(['sale_line_id', 'product_variant_id'], 'sale_components_line_variant_idx');
            $table->foreign(['sale_line_id', 'business_id'], 'sale_components_sale_line_business_fk')
                ->references(['id', 'business_id'])->on('sale_lines')->cascadeOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'sale_components_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'sale_components_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->decimal('amount', 14, 2);
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->string('method', 32);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'operation_key'], 'sale_payments_business_operation_unique');
            $table->index(['sale_id', 'paid_at'], 'sale_payments_sale_paid_idx');
            $table->foreign(['sale_id', 'business_id'], 'sale_payments_sale_business_fk')
                ->references(['id', 'business_id'])->on('sales')->cascadeOnDelete();
        });

        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('location_id');
            $table->string('return_number', 40)->nullable();
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('refund_total', 14, 2)->default(0);
            $table->string('reason', 100);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('inventory_operation_id')->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'sale_returns_id_business_unique');
            $table->unique(['business_id', 'return_number'], 'sale_returns_business_number_unique');
            $table->unique(['business_id', 'operation_key'], 'sale_returns_business_operation_unique');
            $table->unique(['business_id', 'inventory_operation_id'], 'sale_returns_business_inventory_op_unique');
            $table->index(['business_id', 'sale_id', 'processed_at'], 'sale_returns_business_sale_processed_idx');
            $table->foreign(['sale_id', 'business_id'], 'sale_returns_sale_business_fk')
                ->references(['id', 'business_id'])->on('sales')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'sale_returns_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'sale_returns_inventory_op_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('sale_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_return_id');
            $table->unsignedBigInteger('sale_line_id');
            $table->unsignedBigInteger('sale_line_component_id')->nullable();
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->boolean('is_component')->default(false);
            $table->boolean('restock')->default(true);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost_snapshot', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->unique(['sale_return_id', 'serialized_device_id'], 'sale_return_lines_return_device_unique');
            $table->index(['sale_line_id', 'business_id'], 'sale_return_lines_sale_line_idx');
            $table->foreign(['sale_return_id', 'business_id'], 'sale_return_lines_return_business_fk')
                ->references(['id', 'business_id'])->on('sale_returns')->cascadeOnDelete();
            $table->foreign(['sale_line_id', 'business_id'], 'sale_return_lines_sale_line_business_fk')
                ->references(['id', 'business_id'])->on('sale_lines')->restrictOnDelete();
            $table->foreign(['sale_line_component_id', 'business_id'], 'sale_return_lines_component_business_fk')
                ->references(['id', 'business_id'])->on('sale_line_components')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'sale_return_lines_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        Schema::create('sale_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('sale_return_id');
            $table->decimal('amount', 14, 2);
            $table->uuid('operation_key');
            $table->string('method', 32);
            $table->string('reference', 100)->nullable();
            $table->timestamp('refunded_at');
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'operation_key'], 'sale_refunds_business_operation_unique');
            $table->foreign(['sale_id', 'business_id'], 'sale_refunds_sale_business_fk')
                ->references(['id', 'business_id'])->on('sales')->restrictOnDelete();
            $table->foreign(['sale_return_id', 'business_id'], 'sale_refunds_return_business_fk')
                ->references(['id', 'business_id'])->on('sale_returns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_refunds');
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_line_components');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
