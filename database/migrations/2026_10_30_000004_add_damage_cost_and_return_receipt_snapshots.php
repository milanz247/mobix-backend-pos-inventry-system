<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damaged_stock_entries', fn (Blueprint $table) => $table->decimal('unit_cost', 14, 2)->default(0));
        DB::statement('UPDATE damaged_stock_entries d JOIN product_variants v ON v.id = d.product_variant_id LEFT JOIN sale_return_lines r ON r.id = d.sale_return_line_id SET d.unit_cost = COALESCE(r.unit_cost_snapshot, v.cost_price, 0)');
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->decimal('net_total_after', 14, 2)->nullable();
            $table->decimal('net_paid_after', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropColumn(['balance_after', 'net_total_after', 'net_paid_after']));
        Schema::table('damaged_stock_entries', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
    }
};
