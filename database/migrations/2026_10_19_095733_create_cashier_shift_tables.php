<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('active_cashier_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('reference', 48)->unique();
            $table->uuid('operation_key')->unique();
            $table->string('status', 24)->default('open');
            $table->decimal('opening_cash', 14, 2);
            $table->text('opening_note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('actual_cash', 14, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->text('variance_reason')->nullable();
            $table->text('closing_note')->nullable();
            $table->json('closing_summary')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('approver_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'location_id', 'opened_at']);
            $table->index(['cashier_id', 'status']);
        });
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reference', 48)->unique();
            $table->uuid('operation_key')->unique();
            $table->string('type', 24);
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500);
            $table->string('supporting_reference', 150)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('shift_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->json('details');
            $table->timestamp('created_at');
        });
        foreach (['sales', 'sale_payments', 'sale_refunds'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['sale_refunds', 'sale_payments', 'sales'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('cashier_shift_id'));
        }
        Schema::dropIfExists('shift_events');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
    }
};
