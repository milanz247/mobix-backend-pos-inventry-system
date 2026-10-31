<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table): void {
            $table->boolean('is_service')->default(false)->after('is_combo');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropColumn('is_service'));
    }
};
