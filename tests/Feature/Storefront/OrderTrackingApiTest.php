<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Models\WebOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function trackingFixture(?Business $business = null, string $method = 'cod'): array
{
    $business ??= Business::factory()->for(User::factory()->create(), 'owner')->create();
    $location = Location::factory()->for($business)->create();
    $credential = bin2hex(random_bytes(32));
    $order = WebOrder::query()->create([
        'business_id' => $business->id, 'location_id' => $location->id, 'reference' => (string) Str::ulid(),
        'submission_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'payload'),
        'guest_access_token_hash' => hash('sha256', $credential), 'status' => 'pending_confirmation',
        'payment_method' => $method, 'payment_status' => $method === 'cod' ? 'cod_due' : 'transfer_awaiting_proof',
        'currency' => $business->currency, 'subtotal' => '1000.00', 'discount_total' => '0.00',
        'delivery_fee' => '250.00', 'total' => '1250.00',
        'contact_snapshot' => ['name' => 'Customer', 'phone' => '0771234567', 'email' => 'customer@example.test', 'private' => 'secret'],
        'address_snapshot' => ['line1' => 'Main Road', 'city' => 'Colombo', 'private' => 'secret'],
        'delivery_area_code_snapshot' => 'central', 'delivery_area_name_snapshot' => 'Central',
        'location_code_snapshot' => $location->code, 'location_name_snapshot' => $location->name,
        'submitted_at' => now(), 'reservation_expires_at' => now()->addMinutes(30),
    ]);
    DB::table('web_order_items')->insert(['business_id' => $business->id, 'web_order_id' => $order->id,
        'line_number' => 1, 'product_name_snapshot' => 'Used phone', 'product_slug_snapshot' => 'used-phone',
        'variant_name_snapshot' => '128 GB', 'condition_snapshot' => 'used', 'quantity' => '1.000',
        'unit_price' => '1000.00', 'line_total' => '1000.00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('web_order_status_histories')->insert(['business_id' => $business->id, 'web_order_id' => $order->id,
        'from_status' => null, 'to_status' => 'pending_confirmation', 'actor_type' => 'staff', 'actor_id' => 23,
        'reason_code' => 'secret_internal_reason', 'customer_message' => 'private IMEI 123456789012345',
        'created_at' => now()->subMinute()]);

    return compact('business', 'order', 'credential');
}

function trackingGet($test, array $fixture, ?string $credential = null)
{
    return $test->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        $credential === null ? [] : ['X-Storefront-Order-Credential' => $credential]);
}

test('valid per-order credential returns only customer-safe snapshots and ordered history', function () {
    $fixture = trackingFixture();
    DB::table('web_order_status_histories')->insert(['business_id' => $fixture['business']->id,
        'web_order_id' => $fixture['order']->id, 'from_status' => 'pending_confirmation',
        'to_status' => 'confirmed', 'actor_type' => 'staff', 'actor_id' => 99,
        'reason_code' => 'private_phone_number', 'customer_message' => 'staff note', 'created_at' => now()]);
    $response = trackingGet($this, $fixture, $fixture['credential'])->assertOk()
        ->assertJsonPath('data.status', 'pending_confirmation')
        ->assertJsonPath('data.payment_status', 'cod_due')
        ->assertJsonPath('data.items.0.condition', 'used')
        ->assertJsonPath('data.history.0.status', 'pending_confirmation')
        ->assertJsonPath('data.history.1.status', 'confirmed')
        ->assertJsonPath('data.address.line1', 'Main Road');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->not->toContain($fixture['credential'], 'guest_access_token_hash',
        'serialized_device_id', 'active_device_id', 'reservation_line_key', 'imei', 'IMEI',
        'secret_internal_reason', 'private_phone_number', 'staff note', 'actor_id', 'location_id', 'cost_price');
});

test('missing incorrect and malformed credentials all return the same generic not-found', function () {
    $fixture = trackingFixture();
    foreach ([null, str_repeat('b', 64), 'invalid'] as $credential) {
        trackingGet($this, $fixture, $credential)->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    }
    $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference.'?credential='.$fixture['credential'])
        ->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('one order credential cannot read another order', function () {
    $first = trackingFixture();
    $second = trackingFixture($first['business']);
    trackingGet($this, $second, $first['credential'])->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    trackingGet($this, $second, $second['credential'])->assertOk();
});

test('orders from another business remain inaccessible through the public storefront', function () {
    $owner = User::factory()->create();
    $otherBusiness = Business::factory()->for($owner, 'owner')->create(['setup_key' => 'secondary']);
    $fixture = trackingFixture($otherBusiness);
    trackingGet($this, $fixture, $fixture['credential'])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

test('COD confirmation status never claims collection and bank transfer stays unverified', function () {
    $cod = trackingFixture();
    $cod['order']->update(['status' => 'confirmed']);
    $this->getJson('/api/storefront/v1/orders/'.$cod['order']->reference,
        ['X-Storefront-Order-Credential' => $cod['credential']])->assertOk()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment_status', 'cod_due');
    $bank = trackingFixture($cod['business'], 'bank_transfer');
    trackingGet($this, $bank, $bank['credential'])->assertOk()
        ->assertJsonPath('data.payment_status', 'transfer_awaiting_proof');
    $bank['order']->update(['payment_status' => 'transfer_awaiting_verification']);
    trackingGet($this, $bank, $bank['credential'])->assertOk()
        ->assertJsonPath('data.payment_status', 'transfer_awaiting_verification');
});

test('invalid reference and unknown order do not reveal existence or internal details', function () {
    $fixture = trackingFixture();
    $this->getJson('/api/storefront/v1/orders/not-an-ulid',
        ['X-Storefront-Order-Credential' => $fixture['credential']])->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    $this->getJson('/api/storefront/v1/orders/'.Str::ulid(),
        ['X-Storefront-Order-Credential' => $fixture['credential']])->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});
