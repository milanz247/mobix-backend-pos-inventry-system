<?php

use App\Models\Role;
use App\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_delivery_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('base_delivery_fee', 16, 2);
            $table->char('currency', 3);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_delivery_areas_scope_unique');
            $table->unique(['business_id', 'code'], 'web_delivery_areas_code_unique');
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('web_location_delivery_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('delivery_area_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('delivery_fee_override', 16, 2)->nullable();
            $table->unsignedInteger('selection_priority')->default(100);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['delivery_area_id', 'location_id'], 'web_location_areas_unique');
            $table->index(['business_id', 'delivery_area_id', 'is_active', 'selection_priority'], 'web_location_areas_selection_idx');
            $table->foreign(['delivery_area_id', 'business_id'], 'web_location_areas_area_fk')->references(['id', 'business_id'])->on('web_delivery_areas')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'web_location_areas_location_fk')->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('web_order_offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_cart_id');
            $table->unsignedBigInteger('web_quote_id');
            $table->unsignedBigInteger('delivery_area_id');
            $table->unsignedBigInteger('location_id');
            $table->string('delivery_area_code_snapshot', 80);
            $table->string('delivery_area_name_snapshot', 150);
            $table->char('reference', 26)->unique();
            $table->char('principal_hash', 64);
            $table->string('status', 20)->default('offered');
            $table->char('currency', 3);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('delivery_fee', 16, 2);
            $table->decimal('total', 16, 2);
            $table->string('payment_method', 24);
            $table->json('contact_snapshot');
            $table->json('address_snapshot');
            $table->char('terms_digest', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'web_order_offers_scope_unique');
            $table->index(['business_id', 'web_cart_id', 'status', 'expires_at'], 'web_order_offers_cart_idx');
            $table->foreign(['web_cart_id', 'business_id'], 'web_order_offers_cart_fk')->references(['id', 'business_id'])->on('web_carts')->restrictOnDelete();
            $table->foreign(['web_quote_id', 'business_id'], 'web_order_offers_quote_fk')->references(['id', 'business_id'])->on('web_quotes')->restrictOnDelete();
            $table->foreign(['delivery_area_id', 'business_id'], 'web_order_offers_area_fk')->references(['id', 'business_id'])->on('web_delivery_areas')->restrictOnDelete();
            $table->foreign(['location_id', 'business_id'], 'web_order_offers_location_fk')->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('web_order_offer_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_offer_id');
            $table->char('source_cart_item_key', 26);
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->json('private_allocation')->nullable();
            $table->string('product_slug', 180);
            $table->string('product_name', 180);
            $table->string('variant_name', 150);
            $table->string('condition_snapshot', 32)->nullable();
            $table->json('included_device_conditions')->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->timestamps();
            $table->unique(['web_order_offer_id', 'line_number'], 'web_offer_items_line_unique');
            $table->foreign(['web_order_offer_id', 'business_id'], 'web_offer_items_offer_fk')->references(['id', 'business_id'])->on('web_order_offers')->restrictOnDelete();
            $table->foreign(['product_id', 'business_id'], 'web_offer_items_product_fk')->references(['id', 'business_id'])->on('products')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'business_id'], 'web_offer_items_variant_fk')->references(['id', 'business_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['serialized_device_id', 'business_id'], 'web_offer_items_device_fk')->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE web_delivery_areas ADD CONSTRAINT web_delivery_fee_nonnegative CHECK (base_delivery_fee >= 0)');
            DB::statement('ALTER TABLE web_location_delivery_areas ADD CONSTRAINT web_location_fee_nonnegative CHECK (delivery_fee_override IS NULL OR delivery_fee_override >= 0)');
            DB::statement('ALTER TABLE web_order_offers ADD CONSTRAINT web_offer_amounts_nonnegative CHECK (subtotal >= 0 AND discount_total >= 0 AND delivery_fee >= 0 AND total >= 0)');
            DB::statement('ALTER TABLE web_order_offer_items ADD CONSTRAINT web_offer_item_positive CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)');
        }

        $permission = Permission::findOrCreate('storefront.manage-delivery', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_offer_items');
        Schema::dropIfExists('web_order_offers');
        Schema::dropIfExists('web_location_delivery_areas');
        Schema::dropIfExists('web_delivery_areas');
        Permission::query()->where('name', 'storefront.manage-delivery')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
