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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id');
            $table->foreignId('brand_id')->nullable();
            $table->string('name', 180);
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('variant', 150)->nullable();
            $table->string('product_type', 32);
            $table->string('condition', 24)->nullable();
            $table->text('description')->nullable();
            $table->decimal('cost_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->string('image_path')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->index(['business_id', 'status', 'name']);
            $table->index(['business_id', 'category_id']);
            $table->index(['business_id', 'brand_id']);

            $table->foreign(['category_id', 'business_id'])
                ->references(['id', 'business_id'])
                ->on('categories')
                ->restrictOnDelete();
            $table->foreign(['brand_id', 'business_id'])
                ->references(['id', 'business_id'])
                ->on('brands')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
