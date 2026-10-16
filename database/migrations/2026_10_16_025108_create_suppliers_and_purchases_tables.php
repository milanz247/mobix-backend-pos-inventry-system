<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 32);
            $table->string('alternate_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'suppliers_id_business_unique');
            $table->unique(['business_id', 'name'], 'suppliers_business_name_unique');
            $table->index(['business_id', 'status', 'name'], 'suppliers_business_status_name_idx');
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('location_id');
            $table->string('invoice_number', 100);
            $table->uuid('operation_key');
            $table->date('invoice_date');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->string('status', 24)->default('draft');
            $table->string('payment_status', 24)->default('unpaid');
            $table->unsignedBigInteger('inventory_operation_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'purchases_id_business_unique');
            $table->unique(['business_id', 'invoice_number'], 'purchases_business_invoice_unique');
            $table->unique(['business_id', 'operation_key'], 'purchases_business_operation_unique');
            $table->unique(['business_id', 'inventory_operation_id'], 'purchases_business_inventory_op_unique');
            $table->index(['business_id', 'status', 'invoice_date'], 'purchases_business_status_date_idx');
            $table->index(['business_id', 'supplier_id', 'invoice_date'], 'purchases_business_supplier_date_idx');
            $table->index(['business_id', 'location_id', 'invoice_date'], 'purchases_business_location_date_idx');
            $table->foreign(['supplier_id', 'business_id'], 'purchases_supplier_business_fk')
                ->references(['id', 'business_id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'purchases_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'purchases_inventory_op_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('tracking_mode', 24);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'purchase_lines_id_business_unique');
            $table->unique(['purchase_id', 'product_variant_id'], 'purchase_lines_purchase_variant_unique');
            $table->index(['business_id', 'product_variant_id'], 'purchase_lines_business_variant_idx');
            $table->foreign(['purchase_id', 'business_id'], 'purchase_lines_purchase_business_fk')
                ->references(['id', 'business_id'])->on('purchases')->cascadeOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'purchase_lines_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('purchase_line_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_line_id');
            $table->string('imei', 32);
            $table->string('imei2', 32)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('condition', 24);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('unit_selling_price', 14, 2)->nullable();
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'imei'], 'purchase_line_devices_business_imei_unique');
            $table->unique(['business_id', 'serialized_device_id'], 'purchase_line_devices_business_device_unique');
            $table->index(['purchase_line_id', 'business_id'], 'purchase_line_devices_line_business_idx');
            $table->foreign(['purchase_line_id', 'business_id'], 'purchase_line_devices_line_business_fk')
                ->references(['id', 'business_id'])->on('purchase_lines')->cascadeOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'purchase_line_devices_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        Schema::create('purchase_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_id');
            $table->decimal('amount', 14, 2);
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->date('payment_date');
            $table->string('method', 32);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'operation_key'], 'purchase_payments_business_opkey_unique');
            $table->index(['purchase_id', 'payment_date'], 'purchase_payments_purchase_date_idx');
            $table->foreign(['purchase_id', 'business_id'], 'purchase_payments_purchase_business_fk')
                ->references(['id', 'business_id'])->on('purchases')->cascadeOnDelete();
        });

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('location_id');
            $table->string('return_number', 100);
            $table->date('return_date');
            $table->string('status', 16)->default('posted');
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('operation_key');
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('inventory_operation_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'purchase_returns_id_business_unique');
            $table->unique(['business_id', 'return_number'], 'purchase_returns_business_number_unique');
            $table->unique(['business_id', 'operation_key'], 'purchase_returns_business_opkey_unique');
            $table->unique(['business_id', 'inventory_operation_id'], 'purchase_returns_business_invop_unique');
            $table->index(['business_id', 'purchase_id', 'return_date'], 'purchase_returns_business_purchase_date_idx');
            $table->foreign(['purchase_id', 'business_id'], 'purchase_returns_purchase_business_fk')
                ->references(['id', 'business_id'])->on('purchases')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'purchase_returns_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'purchase_returns_inventory_op_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('purchase_line_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->unique(['business_id', 'serialized_device_id'], 'purchase_return_lines_business_device_unique');
            $table->index(['purchase_line_id', 'business_id'], 'purchase_return_lines_line_business_idx');
            $table->foreign(['purchase_return_id', 'business_id'], 'purchase_return_lines_return_business_fk')
                ->references(['id', 'business_id'])->on('purchase_returns')->cascadeOnDelete();
            $table->foreign(['purchase_line_id', 'business_id'], 'purchase_return_lines_line_business_fk')
                ->references(['id', 'business_id'])->on('purchase_lines')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'purchase_return_lines_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'purchase_return_lines_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_line_devices');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
