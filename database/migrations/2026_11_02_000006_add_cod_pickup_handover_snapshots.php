<?php

use App\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->decimal('product_tax_rate_snapshot', 5, 2)->nullable();
            $table->decimal('product_tax_total_snapshot', 16, 2)->nullable();
        });
        Schema::table('web_order_items', function (Blueprint $table): void {
            $table->string('sku_snapshot', 80)->nullable();
            $table->string('barcode_snapshot', 100)->nullable();
            $table->string('tracking_mode_snapshot', 24)->nullable();
            $table->boolean('is_combo_snapshot')->nullable();
            $table->decimal('tax_total_snapshot', 16, 2)->nullable();
        });
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_cod_order_id')->nullable();
            $table->unique('web_cod_order_id', 'sale_payments_web_cod_order_unique');
            $table->foreign(['web_cod_order_id', 'business_id'], 'sale_payments_web_cod_order_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
        });
        $permission = Permission::findOrCreate('storefront.handover-orders', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('sale_payments')->whereNotNull('web_cod_order_id')->exists()
            || DB::table('web_orders')->whereNotNull('product_tax_total_snapshot')->exists()) {
            throw new RuntimeException('Cannot remove accepted tax snapshots or historical COD collection origins.');
        }
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropForeign('sale_payments_web_cod_order_fk');
            $table->dropUnique('sale_payments_web_cod_order_unique');
            $table->dropColumn('web_cod_order_id');
        });
        Schema::table('web_order_items', fn (Blueprint $table) => $table->dropColumn([
            'sku_snapshot', 'barcode_snapshot', 'tracking_mode_snapshot', 'is_combo_snapshot', 'tax_total_snapshot',
        ]));
        Schema::table('web_orders', fn (Blueprint $table) => $table->dropColumn([
            'product_tax_rate_snapshot', 'product_tax_total_snapshot',
        ]));
        Permission::query()->where('guard_name', 'web')->where('name', 'storefront.handover-orders')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
