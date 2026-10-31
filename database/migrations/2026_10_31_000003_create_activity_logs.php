<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 150)->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 100);
            $table->string('subject_id', 100)->nullable();
            $table->string('subject_name', 255)->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_name', 255)->nullable();
            $table->json('changes')->nullable();
            $table->string('route', 150)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('session_fingerprint', 64)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['business_id', 'product_id', 'created_at']);
        });
        foreach (['customers.delete', 'activity-logs.view', 'product-activity.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['customers.delete', 'activity-logs.view', 'product-activity.view'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
