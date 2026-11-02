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
        Schema::create('web_order_bank_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name_snapshot', 150);
            $table->text('internal_note')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['business_id', 'web_order_id'], 'web_bank_confirmations_order_unique');
            $table->foreign(['web_order_id', 'business_id'], 'web_bank_confirmations_order_fk')
                ->references(['id', 'business_id'])->on('web_orders')->restrictOnDelete();
            $table->foreign('actor_user_id', 'web_bank_confirmations_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
        $permission = Permission::findOrCreate('storefront.confirm-bank-orders', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_bank_confirmations');
        Permission::query()->where('guard_name', 'web')->where('name', 'storefront.confirm-bank-orders')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
