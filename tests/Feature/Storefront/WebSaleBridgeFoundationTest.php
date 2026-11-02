<?php

use App\Models\Business;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WebBankTransferAttempt;
use App\Models\WebBankTransferDecision;
use App\Models\WebOrder;
use App\Models\WebOrderFulfillment;
use App\Models\WebOrderPayment;
use App\SaleSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function bridgeOrder(Business $business, Location $location, string $method = 'cod', ?string $mode = 'shop_pickup'): WebOrder
{
    return WebOrder::query()->create([
        'business_id' => $business->id, 'location_id' => $location->id,
        'reference' => (string) Str::ulid(), 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', (string) Str::uuid()), 'status' => 'ready',
        'payment_method' => $method, 'payment_status' => $method === 'cod' ? 'cod_due' : 'paid_verified',
        'fulfillment_mode' => $mode, 'currency' => $business->currency,
        'subtotal' => '100.00', 'discount_total' => '0.00', 'delivery_fee' => '0.00', 'total' => '100.00',
    ]);
}

function bridgeFixture(): array
{
    $actor = User::factory()->create();
    $business = Business::factory()->for($actor, 'owner')->create();
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    $order = bridgeOrder($business, $location);

    return compact('actor', 'business', 'location', 'order');
}

function bridgeSale(array $fixture, WebOrder $order): Sale
{
    return Sale::factory()->create([
        'business_id' => $fixture['business']->id, 'location_id' => $fixture['location']->id,
        'cashier_id' => $fixture['actor']->id, 'source' => SaleSource::Ecommerce,
        'web_order_id' => $order->id,
    ]);
}

function bridgeFulfillment(array $fixture, WebOrder $order, Sale $sale, InventoryOperation $operation, ?string $key = null): WebOrderFulfillment
{
    if ($sale->inventory_operation_id === null) {
        $sale->update(['inventory_operation_id' => $operation->id]);
    }

    return WebOrderFulfillment::query()->create([
        'business_id' => $fixture['business']->id, 'web_order_id' => $order->id,
        'sale_id' => $sale->id, 'inventory_operation_id' => $operation->id,
        'handover_mode' => 'shop_pickup', 'handover_status' => 'posted',
        'operation_key' => $key ?? (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()),
        'handover_actor_id' => $fixture['actor']->id, 'handover_at' => now(),
    ]);
}

test('existing POS sources remain valid and Ecommerce source requires an order link', function () {
    expect(SaleSource::InStorePos->value)->toBe('in_store_pos')
        ->and(SaleSource::Other->value)->toBe('other')
        ->and(SaleSource::Ecommerce->value)->toBe('ecommerce');
    $fixture = bridgeFixture();
    $pos = Sale::factory()->create(['business_id' => $fixture['business']->id,
        'location_id' => $fixture['location']->id, 'cashier_id' => $fixture['actor']->id]);
    expect($pos->web_order_id)->toBeNull();
    $web = bridgeSale($fixture, $fixture['order']);
    expect($web->webOrder->id)->toBe($fixture['order']->id)
        ->and($fixture['order']->fresh()->sale->id)->toBe($web->id);
});

test('a web order can link to at most one sale and a foreign-business order is rejected', function () {
    $fixture = bridgeFixture();
    bridgeSale($fixture, $fixture['order']);
    expect(fn () => bridgeSale($fixture, $fixture['order']))->toThrow(QueryException::class);

    $other = Business::factory()->for(User::factory()->create(), 'owner')->create(['setup_key' => 'secondary']);
    $otherLocation = Location::factory()->for($other)->create();
    $otherOrder = bridgeOrder($other, $otherLocation);
    expect(fn () => bridgeSale($fixture, $otherOrder))->toThrow(QueryException::class);
});

test('fulfillment is unique by order sale inventory operation and operation key', function () {
    $fixture = bridgeFixture();
    $sale = bridgeSale($fixture, $fixture['order']);
    $operation = InventoryOperation::factory()->create(['business_id' => $fixture['business']->id,
        'performed_by' => $fixture['actor']->id]);
    $key = (string) Str::uuid();
    bridgeFulfillment($fixture, $fixture['order'], $sale, $operation, $key);
    expect(fn () => bridgeFulfillment($fixture, $fixture['order'], $sale, $operation))->toThrow(QueryException::class);

    $otherOrder = bridgeOrder($fixture['business'], $fixture['location']);
    $otherSale = bridgeSale($fixture, $otherOrder);
    $otherOperation = InventoryOperation::factory()->create(['business_id' => $fixture['business']->id,
        'performed_by' => $fixture['actor']->id]);
    expect(fn () => bridgeFulfillment($fixture, $otherOrder, $otherSale, $otherOperation, $key))->toThrow(QueryException::class);
    expect(fn () => bridgeFulfillment($fixture, $otherOrder, $sale, $otherOperation))->toThrow(QueryException::class);
    expect(fn () => bridgeFulfillment($fixture, $otherOrder, $otherSale, $operation))->toThrow(QueryException::class);
});

test('one bank decision cannot be linked to two sale payment projections', function () {
    $fixture = bridgeFixture();
    $order = bridgeOrder($fixture['business'], $fixture['location'], 'bank_transfer', 'home_delivery');
    $payment = WebOrderPayment::query()->create(['business_id' => $fixture['business']->id,
        'web_order_id' => $order->id, 'method' => 'bank_transfer', 'status' => 'paid_verified',
        'currency' => $fixture['business']->currency, 'amount_due' => '100.00']);
    $attempt = WebBankTransferAttempt::query()->create([
        'business_id' => $fixture['business']->id, 'web_order_id' => $order->id,
        'web_order_payment_id' => $payment->id, 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', (string) Str::uuid()), 'transfer_reference' => 'TEST',
        'evidence_path' => 'private/test', 'evidence_mime' => 'image/png', 'evidence_size' => 100,
        'submitted_at' => now(),
    ]);
    $decision = WebBankTransferDecision::query()->create([
        'business_id' => $fixture['business']->id, 'web_order_id' => $order->id,
        'web_bank_transfer_attempt_id' => $attempt->id, 'actor_user_id' => $fixture['actor']->id,
        'actor_name_snapshot' => $fixture['actor']->name, 'decision' => 'verified',
        'received_amount' => '100.00', 'reviewed_at' => now(),
    ]);
    $sale = bridgeSale($fixture, $order);
    $attributes = ['sale_id' => $sale->id, 'business_id' => $fixture['business']->id,
        'method' => 'bank_transfer', 'web_bank_transfer_decision_id' => $decision->id,
        'received_by' => $fixture['actor']->id];
    SalePayment::factory()->create($attributes);
    expect(fn () => SalePayment::factory()->create($attributes))->toThrow(QueryException::class);
});

test('historical orders without a mode remain readable', function () {
    $fixture = bridgeFixture();
    $fixture['order']->update(['fulfillment_mode' => null]);
    expect(WebOrder::query()->findOrFail($fixture['order']->id)->fulfillment_mode)->toBeNull();
});

test('MySQL enforces E1 source and fulfillment checks even for raw writes', function () {
    $fixture = bridgeFixture();
    $sale = bridgeSale($fixture, $fixture['order']);
    expect(fn () => DB::table('sales')->where('id', $sale->id)->update(['source' => 'in_store_pos']))
        ->toThrow(QueryException::class);
    $operation = InventoryOperation::factory()->create(['business_id' => $fixture['business']->id,
        'performed_by' => $fixture['actor']->id]);
    $fulfillment = bridgeFulfillment($fixture, $fixture['order'], $sale, $operation);
    expect(fn () => DB::table('web_order_fulfillments')->where('id', $fulfillment->id)
        ->update(['handover_status' => 'pending']))->toThrow(QueryException::class);
    expect(fn () => DB::table('web_order_fulfillments')->where('id', $fulfillment->id)
        ->update(['handover_mode' => 'home_delivery']))->toThrow(QueryException::class);
});

test('MySQL retains E1 composite foreign keys and unique indexes', function () {
    $database = DB::connection()->getDatabaseName();
    $foreignKeys = collect(DB::select('SELECT CONSTRAINT_NAME AS name FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$database]))
        ->pluck('name')->all();
    foreach (['sales_web_order_business_fk', 'web_fulfillments_mode_business_fk',
        'web_fulfillments_sale_order_business_fk', 'web_fulfillments_sale_inventory_business_fk',
        'web_fulfillments_inventory_business_fk', 'sale_payments_bank_decision_business_fk',
        'sale_payments_cod_sale_order_fk'] as $name) {
        expect($foreignKeys)->toContain($name);
    }
    $indexes = collect(DB::select('SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0', [$database]))
        ->pluck('name')->all();
    foreach (['sales_business_web_order_unique', 'web_fulfillments_order_unique',
        'web_fulfillments_sale_unique', 'web_fulfillments_inventory_unique',
        'web_fulfillments_operation_unique', 'sale_payments_web_cod_order_unique',
        'sale_payments_web_bank_decision_unique'] as $name) {
        expect($indexes)->toContain($name);
    }
});
