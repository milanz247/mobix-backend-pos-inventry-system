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
        Schema::create('web_home_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->restrictOnDelete();
            $table->string('headline', 150);
            $table->string('eyebrow', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('button_text', 60)->nullable();
            $table->string('button_href', 180)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('image_alt', 180)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        $permission = Permission::findOrCreate('storefront.manage-homepage', 'web');
        Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('web_home_banners') && DB::table('web_home_banners')->exists()) {
            throw new RuntimeException('Cannot remove configured homepage banner history.');
        }
        Schema::dropIfExists('web_home_banners');
        Permission::query()->where('name', 'storefront.manage-homepage')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
