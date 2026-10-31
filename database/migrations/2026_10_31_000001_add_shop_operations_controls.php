<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->unsignedSmallInteger('credit_days')->default(30);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->index();
        });
        Schema::table('businesses', function (Blueprint $table): void {
            $table->decimal('tax_rate', 5, 2)->default(0);
        });
        foreach (['sale_lines', 'sale_return_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('tax_total', 14, 2)->default(0);
            });
        }
        Schema::create('reorder_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
            $table->unique(['location_id', 'product_variant_id']);
        });
        Schema::create('held_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->index(['business_id', 'location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
        Schema::dropIfExists('reorder_levels');
        foreach (['sale_lines', 'sale_return_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('tax_total'));
        }
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('due_date'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['credit_limit', 'credit_days']));
    }
};
