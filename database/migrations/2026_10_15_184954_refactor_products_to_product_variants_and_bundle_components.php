<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['id', 'business_id']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name', 150)->default('Default');
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('model_number', 150)->nullable();
            $table->string('storage_capacity', 40)->nullable();
            $table->string('color', 80)->nullable();
            $table->json('variant_attributes')->nullable();
            $table->string('condition', 24)->nullable();
            $table->string('tracking_mode', 24)->default('quantity_based');
            $table->decimal('cost_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('minimum_selling_price', 12, 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['product_id', 'business_id'])
                ->references(['id', 'business_id'])
                ->on('products')
                ->restrictOnDelete();
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->unique(['id', 'business_id']);
            $table->index(['product_id', 'status', 'name']);
            $table->index(['business_id', 'model_number']);
        });

        Schema::create('bundle_components', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->decimal('quantity', 10, 3);
            $table->timestamps();

            $table->primary(['bundle_variant_id', 'component_variant_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['bundle_variant_id', 'business_id'])
                ->references(['id', 'business_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->foreign(['component_variant_id', 'business_id'])
                ->references(['id', 'business_id'])
                ->on('product_variants')
                ->restrictOnDelete();
            $table->index(['component_variant_id', 'business_id']);
        });

        DB::table('products')->orderBy('id')->chunk(200, function ($products): void {
            foreach ($products as $product) {
                DB::table('product_variants')->insert([
                    'business_id' => $product->business_id,
                    'product_id' => $product->id,
                    'name' => $product->variant ?: 'Default',
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'model_number' => $product->model,
                    'storage_capacity' => null,
                    'color' => null,
                    'variant_attributes' => null,
                    'condition' => $product->condition,
                    'tracking_mode' => $product->product_type === 'serialized' ? 'serialized' : 'quantity_based',
                    'cost_price' => $product->cost_price,
                    'selling_price' => $product->selling_price,
                    'minimum_selling_price' => null,
                    'status' => $product->status,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ]);
            }
        });

        DB::table('products')->where('product_type', 'quantity_based')->update(['product_type' => 'simple']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_business_id_sku_unique');
            $table->dropUnique('products_business_id_barcode_unique');
            $table->dropColumn(['sku', 'barcode', 'model', 'variant', 'condition', 'cost_price', 'selling_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('product_variants')->select('product_id')->groupBy('product_id')->havingRaw('count(*) > 1')->exists()
            || DB::table('bundle_components')->exists()
            || DB::table('products')->whereIn('product_type', ['variable', 'combo'])->exists()
            || DB::table('products')->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id'))->exists()) {
            throw new RuntimeException('Cannot roll back while a product has multiple variants, no variant, variable or bundle behavior, or bundle components.');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('variant', 150)->nullable();
            $table->string('condition', 24)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2)->nullable();
        });

        DB::table('products')->orderBy('id')->chunk(200, function ($products): void {
            foreach ($products as $product) {
                $variant = DB::table('product_variants')->where('product_id', $product->id)->first();

                if ($variant === null) {
                    continue;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'model' => $variant->model_number,
                    'variant' => $variant->name === 'Default' ? null : $variant->name,
                    'condition' => $variant->condition,
                    'cost_price' => $variant->cost_price,
                    'selling_price' => $variant->selling_price,
                    'product_type' => $variant->tracking_mode === 'serialized' ? 'serialized' : 'quantity_based',
                ]);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('sku', 64)->nullable(false)->change();
            $table->decimal('cost_price', 12, 2)->nullable(false)->change();
            $table->decimal('selling_price', 12, 2)->nullable(false)->change();
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
        });

        Schema::dropIfExists('bundle_components');
        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['id', 'business_id']);
        });
    }
};
