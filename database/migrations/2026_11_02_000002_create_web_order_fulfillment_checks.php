<?php

use App\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_order_reservations', function (Blueprint $table): void {
            $table->unique(['id', 'web_order_id', 'business_id'], 'web_reservations_check_scope_unique');
        });
        Schema::create('web_order_fulfillment_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('web_order_item_id');
            $table->unsignedBigInteger('web_order_reservation_id');
            $table->string('result', 24);
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('checked_quantity', 14, 3)->nullable();
            $table->string('condition_snapshot', 24)->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedBigInteger('performed_by');
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['business_id', 'web_order_id', 'id'], 'web_pick_checks_order_idx');
            $table->index(['web_order_reservation_id', 'id'], 'web_pick_checks_reservation_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_pick_checks_order_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign(['web_order_item_id', 'web_order_id', 'business_id'], 'web_pick_checks_item_fk')
                ->references(['id', 'web_order_id', 'business_id'])->on('web_order_items')->restrictOnDelete();
            $table->foreign(['web_order_reservation_id', 'web_order_id', 'business_id'], 'web_pick_checks_reservation_fk')
                ->references(['id', 'web_order_id', 'business_id'])->on('web_order_reservations')->restrictOnDelete();
            $table->foreign('performed_by', 'web_pick_checks_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        $names = ['storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $owner = Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first();
        foreach ($names as $name) {
            $owner?->givePermissionTo(Permission::findByName($name, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_fulfillment_checks');
        Schema::table('web_order_reservations', function (Blueprint $table): void {
            $table->dropUnique('web_reservations_check_scope_unique');
        });
        Permission::query()->where('guard_name', 'web')->whereIn('name',
            ['storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
