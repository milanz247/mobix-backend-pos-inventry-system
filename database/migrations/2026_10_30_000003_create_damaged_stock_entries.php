<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damaged_stock_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('remaining_quantity', 14, 3);
            $table->string('reason', 100);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'location_id']);
        });
        Schema::table('sale_lines', fn (Blueprint $table) => $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->boolean('is_exchange')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_return_id'));
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropColumn('is_exchange'));
        Schema::dropIfExists('damaged_stock_entries');
    }
};
