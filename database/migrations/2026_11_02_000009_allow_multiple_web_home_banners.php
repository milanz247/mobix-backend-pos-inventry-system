<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // InnoDB needs an index beginning with business_id for the existing foreign key.
        // Create its replacement before removing the one-banner unique index. Separate
        // statements also let a failed MySQL migration be retried safely.
        if (! Schema::hasIndex('web_home_banners', 'web_home_banners_public_idx')) {
            Schema::table('web_home_banners', function (Blueprint $table): void {
                $table->index(['business_id', 'is_active', 'id'], 'web_home_banners_public_idx');
            });
        }

        if (Schema::hasIndex('web_home_banners', 'web_home_banners_business_id_unique')) {
            Schema::table('web_home_banners', function (Blueprint $table): void {
                $table->dropUnique('web_home_banners_business_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('web_home_banners')->select('business_id')->groupBy('business_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the one-banner constraint while a business has multiple banners.');
        }
        // Restore the foreign-key supporting unique index before dropping its replacement.
        if (! Schema::hasIndex('web_home_banners', 'web_home_banners_business_id_unique')) {
            Schema::table('web_home_banners', function (Blueprint $table): void {
                $table->unique('business_id');
            });
        }

        if (Schema::hasIndex('web_home_banners', 'web_home_banners_public_idx')) {
            Schema::table('web_home_banners', function (Blueprint $table): void {
                $table->dropIndex('web_home_banners_public_idx');
            });
        }
    }
};
