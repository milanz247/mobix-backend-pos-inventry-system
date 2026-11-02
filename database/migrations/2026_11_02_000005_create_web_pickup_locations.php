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
        Schema::create('web_pickup_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id');
            $table->string('public_code', 80);
            $table->string('display_name', 150)->nullable();
            $table->unsignedInteger('selection_priority')->default(100);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'location_id'], 'web_pickup_business_location_unique');
            $table->unique(['business_id', 'public_code'], 'web_pickup_business_code_unique');
            $table->index(['business_id', 'is_active', 'selection_priority', 'id'], 'web_pickup_public_list_idx');
            $table->foreign(['location_id', 'business_id'], 'web_pickup_location_business_fk')
                ->references(['id', 'business_id'])->on('locations')->restrictOnDelete();
        });

        Schema::table('web_order_offers', function (Blueprint $table): void {
            $table->text('pickup_location_address_snapshot')->nullable();
            $table->string('pickup_location_city_snapshot', 100)->nullable();
        });
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->text('pickup_location_address_snapshot')->nullable();
            $table->string('pickup_location_city_snapshot', 100)->nullable();
        });

        $permission = Permission::findOrCreate('storefront.manage-pickup-locations', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('web_order_offers')->whereNotNull('pickup_location_code_snapshot')->exists()
            || DB::table('web_orders')->whereNotNull('pickup_location_code_snapshot')->exists()) {
            throw new RuntimeException('Cannot remove pickup configuration while accepted pickup history exists.');
        }
        Schema::table('web_orders', fn (Blueprint $table) => $table->dropColumn(['pickup_location_address_snapshot', 'pickup_location_city_snapshot']));
        Schema::table('web_order_offers', fn (Blueprint $table) => $table->dropColumn(['pickup_location_address_snapshot', 'pickup_location_city_snapshot']));
        Schema::dropIfExists('web_pickup_locations');
        Permission::query()->where('name', 'storefront.manage-pickup-locations')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
