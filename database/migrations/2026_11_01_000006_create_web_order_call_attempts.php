<?php

use App\Models\Role;
use App\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_order_call_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->char('order_reference_snapshot', 26);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name_snapshot', 150);
            $table->string('outcome', 32);
            $table->text('internal_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'web_order_id', 'created_at'], 'web_order_calls_order_time_idx');
            $table->foreign(['web_order_id', 'business_id'], 'web_order_calls_order_business_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign('actor_user_id', 'web_order_calls_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        $names = ['storefront.view-orders', 'storefront.confirm-orders', 'storefront.cancel-orders'];
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
        Schema::dropIfExists('web_order_call_attempts');
        Permission::query()->where('guard_name', 'web')->whereIn('name',
            ['storefront.view-orders', 'storefront.confirm-orders', 'storefront.cancel-orders'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
