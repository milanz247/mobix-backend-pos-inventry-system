<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_cart_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('serialized_device_id')->nullable()->after('product_variant_id');
            $table->unsignedBigInteger('ordinary_variant_id')->nullable()->storedAs(
                'CASE WHEN serialized_device_id IS NULL THEN product_variant_id ELSE NULL END'
            )->after('serialized_device_id');
        });

        Schema::table('web_cart_items', function (Blueprint $table): void {
            $table->dropUnique(['web_cart_id', 'product_variant_id']);
            $table->unique(['web_cart_id', 'ordinary_variant_id'], 'web_cart_items_ordinary_variant_unique');
            $table->unique(['web_cart_id', 'serialized_device_id'], 'web_cart_items_cart_device_unique');
            $table->foreign(['serialized_device_id', 'business_id'], 'web_cart_items_device_business_fk')
                ->references(['id', 'business_id'])->on('serialized_devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('web_cart_items')->whereNotNull('serialized_device_id')->exists()) {
            throw new RuntimeException('Cannot roll back selected-device cart support while cart lines reference serialized devices. Remove those cart lines first.');
        }

        Schema::table('web_cart_items', function (Blueprint $table): void {
            $table->dropForeign('web_cart_items_device_business_fk');
            $table->dropUnique('web_cart_items_ordinary_variant_unique');
            $table->dropUnique('web_cart_items_cart_device_unique');
            $table->dropColumn(['ordinary_variant_id', 'serialized_device_id']);
            $table->unique(['web_cart_id', 'product_variant_id']);
        });
    }
};
