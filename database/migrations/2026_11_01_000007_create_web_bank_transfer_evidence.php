<?php

use App\Models\Role;
use App\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_order_payments', function (Blueprint $table): void {
            $table->unique(['id', 'web_order_id', 'business_id'], 'web_payments_order_scope_unique');
        });
        Schema::create('web_bank_transfer_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('web_order_payment_id');
            $table->uuid('submission_key');
            $table->char('payload_hash', 64);
            $table->string('transfer_reference', 180);
            $table->decimal('submitted_amount', 16, 2)->nullable();
            $table->date('transfer_date')->nullable();
            $table->string('evidence_path', 255);
            $table->string('evidence_mime', 80);
            $table->unsignedBigInteger('evidence_size');
            $table->string('status', 32)->default('awaiting_verification');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['id', 'business_id', 'web_order_id'], 'web_bank_attempt_scope_unique');
            $table->unique(['web_order_payment_id', 'submission_key'], 'web_bank_attempt_key_unique');
            $table->unique(['web_order_payment_id', 'payload_hash'], 'web_bank_attempt_payload_unique');
            $table->index(['business_id', 'web_order_id', 'submitted_at'], 'web_bank_attempt_order_time_idx');
            $table->foreign(['web_order_payment_id', 'web_order_id', 'business_id'], 'web_bank_attempt_payment_fk')
                ->references(['id', 'web_order_id', 'business_id'])->on('web_order_payments')->restrictOnDelete();
        });
        Schema::create('web_bank_transfer_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('web_order_id');
            $table->unsignedBigInteger('web_bank_transfer_attempt_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name_snapshot', 150);
            $table->string('decision', 32);
            $table->decimal('received_amount', 16, 2);
            $table->text('internal_note')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->unique('web_bank_transfer_attempt_id', 'web_bank_decision_attempt_unique');
            $table->index(['business_id', 'web_order_id', 'reviewed_at'], 'web_bank_decision_order_time_idx');
            $table->foreign(['web_bank_transfer_attempt_id', 'business_id', 'web_order_id'], 'web_bank_decision_attempt_fk')
                ->references(['id', 'business_id', 'web_order_id'])->on('web_bank_transfer_attempts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'web_bank_decision_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE web_bank_transfer_attempts ADD CONSTRAINT web_bank_attempt_amount_check CHECK (submitted_amount IS NULL OR submitted_amount > 0)');
            DB::statement('ALTER TABLE web_bank_transfer_decisions ADD CONSTRAINT web_bank_decision_amount_check CHECK (received_amount >= 0)');
        }
        foreach (['storefront.view-payment-proofs', 'storefront.verify-payments'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            Role::query()->where('name', SystemRole::Owner->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_bank_transfer_decisions');
        Schema::dropIfExists('web_bank_transfer_attempts');
        Schema::table('web_order_payments', fn (Blueprint $table) => $table->dropUnique('web_payments_order_scope_unique'));
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['storefront.view-payment-proofs', 'storefront.verify-payments'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
