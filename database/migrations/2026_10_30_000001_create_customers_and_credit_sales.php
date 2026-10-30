<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 32);
            $table->string('email', 254)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'phone']);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_credit')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('is_credit');
        });
        Schema::dropIfExists('customers');
    }
};
