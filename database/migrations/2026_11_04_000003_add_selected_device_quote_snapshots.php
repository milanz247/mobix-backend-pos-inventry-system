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
            $table->decimal('selected_unit_price', 16, 2)->nullable();
        });
        Schema::table('web_quote_items', function (Blueprint $table): void {
            $table->char('source_cart_item_key', 26)->nullable();
            // Historical snapshots: deliberately not foreign keys to mutable inventory.
            $table->unsignedBigInteger('serialized_device_id')->nullable();
            $table->unsignedBigInteger('selected_location_id')->nullable();
            $table->string('selected_condition', 24)->nullable();
            $table->unique(['web_quote_id', 'source_cart_item_key'], 'web_quote_items_source_line_unique');
        });
        Schema::table('web_order_offers', function (Blueprint $table): void {
            // Null preserves legacy ordinary offers; new offers use the recomputable contract.
            $table->unsignedTinyInteger('terms_digest_version')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('web_cart_items')->whereNotNull('selected_unit_price')->exists()
            || DB::table('web_quote_items')->whereNotNull('serialized_device_id')->exists()
            || DB::table('web_order_offers')->whereNotNull('terms_digest_version')->exists()) {
            throw new RuntimeException('Selected-device snapshots exist. Archive/reconcile them before rolling back; snapshots must not be silently discarded.');
        }
        Schema::table('web_quote_items', function (Blueprint $table): void {
            $table->dropUnique('web_quote_items_source_line_unique');
            $table->dropColumn(['source_cart_item_key', 'serialized_device_id', 'selected_location_id', 'selected_condition']);
        });
        Schema::table('web_cart_items', fn (Blueprint $table) => $table->dropColumn('selected_unit_price'));
        Schema::table('web_order_offers', fn (Blueprint $table) => $table->dropColumn('terms_digest_version'));
    }
};
