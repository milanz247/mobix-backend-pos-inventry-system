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
        Schema::create('product_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 150);
            $table->string('action', 40);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('product_name', 255);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'product_id', 'created_at'], 'product_activity_history_idx');
        });

        $activity = Permission::findOrCreate('product-activity.view', 'web');
        Permission::findOrCreate('sales.customer-link', 'web');
        $owner = Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first();
        $owner?->givePermissionTo($activity);
        $owner?->givePermissionTo(Permission::findByName('sales.customer-link', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_activity_logs');
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['product-activity.view', 'sales.customer-link'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
