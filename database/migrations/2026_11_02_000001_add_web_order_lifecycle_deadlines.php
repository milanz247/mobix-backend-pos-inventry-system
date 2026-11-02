<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->timestamp('cod_confirmation_deadline_at')->nullable();
            $table->timestamp('bank_proof_deadline_at')->nullable();
            $table->index(['business_id', 'status', 'cod_confirmation_deadline_at'], 'web_orders_cod_deadline_idx');
            $table->index(['business_id', 'status', 'bank_proof_deadline_at'], 'web_orders_bank_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('web_orders', function (Blueprint $table): void {
            $table->dropIndex('web_orders_cod_deadline_idx');
            $table->dropIndex('web_orders_bank_deadline_idx');
            $table->dropColumn(['cod_confirmation_deadline_at', 'bank_proof_deadline_at']);
        });
    }
};
