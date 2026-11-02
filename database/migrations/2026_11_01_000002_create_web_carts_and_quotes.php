<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_activity_at');
            $table->timestamps();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'status', 'expires_at']);
        });

        Schema::create('web_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->char('public_key', 26)->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_cart_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
            $table->foreign(['web_cart_id', 'business_id'])->references(['id', 'business_id'])->on('web_carts')->cascadeOnDelete();
            $table->foreign(['product_id', 'business_id'])->references(['id', 'business_id'])->on('products')->cascadeOnDelete();
            $table->foreign(['product_variant_id', 'business_id'])->references(['id', 'business_id'])->on('product_variants')->cascadeOnDelete();
            $table->unique(['web_cart_id', 'product_variant_id']);
            $table->index(['business_id', 'web_cart_id']);
        });

        Schema::create('web_quotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_cart_id');
            $table->char('reference', 26)->unique();
            $table->string('status', 16)->default('indicative');
            $table->char('currency', 3);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('total', 16, 2);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->foreign(['web_cart_id', 'business_id'])->references(['id', 'business_id'])->on('web_carts')->restrictOnDelete();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'web_cart_id', 'created_at']);
        });

        Schema::create('web_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_quote_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_slug', 180);
            $table->string('product_name', 180);
            $table->string('variant_name', 150);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->string('availability', 24);
            $table->boolean('requires_device_confirmation')->default(false);
            $table->timestamps();
            $table->foreign(['web_quote_id', 'business_id'])->references(['id', 'business_id'])->on('web_quotes')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->index(['business_id', 'web_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_quote_items');
        Schema::dropIfExists('web_quotes');
        Schema::dropIfExists('web_cart_items');
        Schema::dropIfExists('web_carts');
    }
};
