<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_device_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained(indexName: 'sds_business_fk')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('serialized_device_id');
            $table->char('token_hash', 64)->unique('sds_token_hash_unique');
            $table->decimal('selected_price', 14, 2);
            $table->timestamp('expires_at')->index('sds_expires_at_idx');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'product_id', 'product_variant_id', 'expires_at'], 'storefront_device_selection_scope_idx');
            $table->foreign(['product_id', 'business_id'], 'sds_product_business_fk')->references(['id', 'business_id'])->on('products')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'sds_variant_business_fk')->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'sds_device_business_fk')->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_device_selections');
    }
};
