<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_additions', fn (Blueprint $table) => $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('sale_returns', fn (Blueprint $table) => $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('sale_lines', fn (Blueprint $table) => $table->foreignId('sale_addition_id')->nullable()->constrained()->restrictOnDelete());
        // Older addition lines were created in the same transaction as their addition ledger.
        DB::statement('UPDATE sale_lines l JOIN sale_additions a ON a.sale_id = l.sale_id AND a.created_at = l.created_at SET l.sale_addition_id = a.id WHERE l.sale_return_id IS NULL AND l.created_at > (SELECT s.created_at FROM sales s WHERE s.id = l.sale_id) AND (SELECT COUNT(*) FROM sale_additions x WHERE x.sale_id = l.sale_id AND x.created_at = l.created_at) = 1');
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_addition_id'));
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropConstrainedForeignId('cashier_shift_id'));
        Schema::table('sale_additions', fn (Blueprint $table) => $table->dropConstrainedForeignId('cashier_shift_id'));
    }
};
