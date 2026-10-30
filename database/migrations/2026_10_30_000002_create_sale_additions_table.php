<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_additions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key');
            $table->string('payload_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->unique(['business_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_additions');
    }
};
