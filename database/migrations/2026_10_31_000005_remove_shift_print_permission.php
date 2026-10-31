<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('guard_name', 'web')->where('name', 'shifts.print')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
