<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('locations', 'locations_id_business_unique', 'unique')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->unique(['id', 'business_id'], 'locations_id_business_unique');
            });
        }

        if (! Schema::hasTable('inventory_operations')) {
            Schema::create('inventory_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('business_id')->constrained()->restrictOnDelete();
                $table->uuid('operation_key');
                $table->char('payload_hash', 64);
                $table->string('type', 24);
                $table->string('status', 16)->default('posted');
                $table->unsignedBigInteger('source_location_id')->nullable();
                $table->unsignedBigInteger('destination_location_id')->nullable();
                $table->string('reference', 100)->nullable();
                $table->string('reason', 80)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('posted_at');
                $table->timestamps();
                $table->unique(['id', 'business_id'], 'inventory_operations_id_business_unique');
                $table->unique(['business_id', 'operation_key']);
                $table->index(['business_id', 'type', 'posted_at']);
                $table->index(['source_location_id', 'posted_at']);
                $table->index(['destination_location_id', 'posted_at']);
                $table->foreign(['source_location_id', 'business_id'], 'inventory_operations_source_business_fk')
                    ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
                $table->foreign(['destination_location_id', 'business_id'], 'inventory_operations_destination_business_fk')
                    ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('inventory_operation_lines')) {
            Schema::create('inventory_operation_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('inventory_operation_id');
                $table->foreignId('business_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('product_variant_id');
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 14, 2)->nullable();
                $table->decimal('unit_selling_price', 14, 2)->nullable();
                $table->string('condition', 24)->nullable();
                $table->timestamps();
                $table->index(['product_variant_id', 'inventory_operation_id'], 'inv_op_lines_variant_operation_idx');
                $table->foreign(['inventory_operation_id', 'business_id'], 'operation_lines_operation_business_fk')
                    ->references(['id', 'business_id'])->on('inventory_operations')->cascadeOnDelete();
                $table->foreign(['product_variant_id', 'business_id'], 'operation_lines_variant_business_fk')
                    ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            });
        }

        if (! Schema::hasIndex('inventory_operation_lines', 'inv_op_lines_variant_operation_idx')) {
            Schema::table('inventory_operation_lines', function (Blueprint $table): void {
                $table->index(['product_variant_id', 'inventory_operation_id'], 'inv_op_lines_variant_operation_idx');
            });
        }

        if (! Schema::hasForeignKey('inventory_operation_lines', 'operation_lines_operation_business_fk')) {
            Schema::table('inventory_operation_lines', function (Blueprint $table): void {
                $table->foreign(['inventory_operation_id', 'business_id'], 'operation_lines_operation_business_fk')
                    ->references(['id', 'business_id'])->on('inventory_operations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('inventory_operation_lines', 'operation_lines_variant_business_fk')) {
            Schema::table('inventory_operation_lines', function (Blueprint $table): void {
                $table->foreign(['product_variant_id', 'business_id'], 'operation_lines_variant_business_fk')
                    ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            });
        }

        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['location_id', 'product_variant_id']);
            $table->index(['business_id', 'location_id']);
            $table->index(['business_id', 'product_variant_id']);
            $table->foreign(['location_id', 'business_id'], 'inventory_stocks_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'inventory_stocks_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('inventory_operation_id');
            $table->uuid('movement_group_id');
            $table->string('movement_type', 32);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('balance_before', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->nullableMorphs('reference');
            $table->string('reason', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'location_id', 'created_at']);
            $table->index(['product_variant_id', 'created_at']);
            $table->index(['movement_type', 'created_at']);
            $table->index('movement_group_id');
            $table->foreign(['location_id', 'business_id'], 'stock_movements_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'stock_movements_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'stock_movements_operation_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('serialized_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('received_operation_id');
            $table->string('imei', 32);
            $table->string('imei2', 32)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('condition', 24);
            $table->string('status', 24)->default('in_stock');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('unit_selling_price', 14, 2)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'serial_number']);
            $table->unique(['id', 'business_id'], 'serialized_devices_id_business_unique');
            $table->index(['business_id', 'location_id', 'status']);
            $table->index(['product_variant_id', 'status']);
            $table->index('imei');
            $table->index('imei2');
            $table->foreign(['location_id', 'business_id'], 'serialized_devices_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'serialized_devices_variant_business_fk')
                ->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['received_operation_id', 'business_id'], 'serialized_devices_operation_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
        });

        Schema::create('serialized_device_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('serialized_device_id');
            $table->string('identifier', 32);
            $table->string('slot', 8);
            $table->unique(['business_id', 'identifier']);
            $table->unique(['serialized_device_id', 'slot']);
            $table->foreign(['serialized_device_id', 'business_id'], 'device_identifiers_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->cascadeOnDelete();
        });

        Schema::create('serialized_device_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('serialized_device_id');
            $table->unsignedBigInteger('inventory_operation_id')->nullable();
            $table->uuid('event_group_id');
            $table->string('event_type', 32);
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->string('status_before', 24)->nullable();
            $table->string('status_after', 24);
            $table->string('reason', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['serialized_device_id', 'created_at']);
            $table->index(['business_id', 'to_location_id', 'created_at'], 'device_events_business_location_created_idx');
            $table->index(['event_type', 'created_at']);
            $table->foreign(['serialized_device_id', 'business_id'], 'device_events_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
            $table->foreign(['inventory_operation_id', 'business_id'], 'device_events_operation_business_fk')
                ->references(['id', 'business_id'])->on('inventory_operations')->restrictOnDelete();
            $table->foreign(['from_location_id', 'business_id'], 'device_events_from_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['to_location_id', 'business_id'], 'device_events_to_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serialized_device_events');
        Schema::dropIfExists('serialized_device_identifiers');
        Schema::dropIfExists('serialized_devices');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('inventory_operation_lines');
        Schema::dropIfExists('inventory_operations');
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropUnique('locations_id_business_unique');
        });
    }
};
