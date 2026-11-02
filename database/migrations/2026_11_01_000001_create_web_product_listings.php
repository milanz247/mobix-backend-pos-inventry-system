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
        Schema::create('web_product_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('slug', 180);
            $table->string('status', 16)->default('draft');
            $table->string('public_title', 180)->nullable();
            $table->text('public_description')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'product_id']);
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'status']);
            $table->foreign(['product_id', 'business_id'])
                ->references(['id', 'business_id'])->on('products')->cascadeOnDelete();
        });

        $permission = Permission::findOrCreate('storefront.publish', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_product_listings');
        Permission::query()->where('name', 'storefront.publish')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
