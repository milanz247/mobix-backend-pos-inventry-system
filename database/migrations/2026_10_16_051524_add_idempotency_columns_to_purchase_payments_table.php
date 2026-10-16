<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $addOperationKey = ! Schema::hasColumn('purchase_payments', 'operation_key');
        $addPayloadHash = ! Schema::hasColumn('purchase_payments', 'payload_hash');

        if ($addOperationKey || $addPayloadHash) {
            Schema::table('purchase_payments', function (Blueprint $table) use ($addOperationKey, $addPayloadHash): void {
                if ($addOperationKey) {
                    $table->uuid('operation_key')->nullable();
                }

                if ($addPayloadHash) {
                    $table->char('payload_hash', 64)->nullable();
                }
            });
        }

        DB::table('purchase_payments')->where(function (Builder $query): void {
            $query->whereNull('operation_key')->orWhereNull('payload_hash');
        })->orderBy('id')->chunkById(100, function ($payments): void {
            foreach ($payments as $payment) {
                $payload = [
                    'payment_date' => (string) $payment->payment_date,
                    'method' => (string) $payment->method,
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                    'amount' => (string) $payment->amount,
                ];

                DB::table('purchase_payments')->where('id', $payment->id)->update([
                    'operation_key' => Str::uuid()->toString(),
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
            }
        });

        if (! Schema::hasIndex('purchase_payments', 'purchase_payments_business_opkey_unique')) {
            Schema::table('purchase_payments', function (Blueprint $table): void {
                $table->unique(['business_id', 'operation_key'], 'purchase_payments_business_opkey_unique');
            });
        }

        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->uuid('operation_key')->nullable(false)->change();
            $table->char('payload_hash', 64)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
