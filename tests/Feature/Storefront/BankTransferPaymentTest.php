<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WebDeliveryArea;
use App\Models\WebLocationDeliveryArea;
use App\Models\WebOrder;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function bankFixture($test, string $method = 'bank_transfer'): array
{
    Storage::fake('local');
    config()->set('storefront.offer_ttl_minutes', 20);
    config()->set('storefront.cod_confirmation_ttl_minutes', 20);
    config()->set('storefront.bank_proof_ttl_minutes', 20);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create(['setup_key' => 'initial']);
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['name' => 'Phone']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create([
        'name' => '128 GB', 'selling_price' => '1000.00', 'cost_price' => '500.00',
    ]);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'phone', 'status' => 'published']);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')
        ->create(['quantity_on_hand' => '5.000']);
    $area = WebDeliveryArea::query()->create(['business_id' => $business->id, 'code' => 'central',
        'name' => 'Central', 'base_delivery_fee' => '250.00', 'currency' => $business->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $business->id, 'delivery_area_id' => $area->id,
        'location_id' => $location->id, 'selection_priority' => 1, 'is_active' => true]);
    $added = $test->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    $cartHeader = ['X-Storefront-Cart-Token' => $added->headers->get('X-Storefront-Cart-Token')];
    $quote = $test->postJson('/api/storefront/v1/quotes', [], $cartHeader)->assertOk();
    $offer = $test->postJson('/api/storefront/v1/order-offers', [
        'quote_reference' => $quote->json('data.reference'),
        'fulfillment_mode' => $method === 'cod' ? 'shop_pickup' : 'home_delivery',
        ...($method === 'cod' ? ['pickup_location_code' => $location->code] : ['delivery_area_code' => 'central']),
        'payment_method' => $method, 'contact' => ['name' => 'Customer', 'phone' => '0771234567'],
        ...($method === 'cod' ? [] : ['address' => ['line1' => 'Main Street', 'city' => 'Colombo']]),
    ], $cartHeader)->assertCreated();
    $submitted = $test->postJson('/api/storefront/v1/orders', [
        'offer_reference' => $offer->json('data.reference'),
        'accepted_terms_digest' => $offer->json('data.terms_digest'), 'accepted' => true,
    ], [...$cartHeader, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $order = WebOrder::query()->where('reference', $submitted->json('data.reference'))->firstOrFail();
    $credential = $submitted->headers->get('X-Storefront-Order-Credential');

    return compact('business', 'owner', 'location', 'order', 'credential');
}

function uploadBankProof($test, array $fixture, ?string $key = null, ?UploadedFile $file = null, array $details = [])
{
    return $test->post('/api/storefront/v1/orders/'.$fixture['order']->reference.'/bank-transfer-proof', [
        'transfer_reference' => 'BANK-REF-1', 'amount_transferred' => '1250.00',
        'transfer_date' => '2026-11-01', 'proof' => $file ?? UploadedFile::fake()->image('proof.jpg'),
        ...$details,
    ], ['Accept' => 'application/json', 'X-Storefront-Order-Credential' => $fixture['credential'],
        'Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

function bankStaff(array $fixture, array $permissions): User
{
    $staff = User::factory()->create();
    foreach ($permissions as $name) {
        $staff->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $fixture['location']->users()->attach($staff->id);

    return $staff;
}

test('authenticated bank proof is private and awaits staff verification', function () {
    $fixture = bankFixture($this);
    $key = (string) Str::uuid();
    uploadBankProof($this, $fixture, $key)->assertCreated()->assertJsonPath('data.payment_status', 'transfer_awaiting_verification');
    expect($fixture['order']->fresh()->payment_status)->toBe('transfer_awaiting_verification');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    $attempt = DB::table('web_bank_transfer_attempts')->sole();
    Storage::disk('local')->assertExists($attempt->evidence_path);
    expect($attempt->evidence_path)->toStartWith('web-bank-transfer-proofs/');
    expect($attempt->evidence_path)->not->toContain('proof.jpg');
    expect(DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->value('status'))
        ->toBe('transfer_awaiting_verification');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'active')->count())->toBe(1);
    uploadBankProof($this, $fixture, $key)->assertOk();
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(1);
    uploadBankProof($this, $fixture)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_PROOF');
});

test('wrong credential public reference and COD order cannot submit bank proof', function () {
    $fixture = bankFixture($this);
    $url = '/api/storefront/v1/orders/'.$fixture['order']->reference.'/bank-transfer-proof';
    $this->post($url, ['proof' => UploadedFile::fake()->image('proof.jpg')],
        ['Accept' => 'application/json'])->assertNotFound();
    $other = $fixture;
    $other['credential'] = str_repeat('a', 64);
    uploadBankProof($this, $other)->assertNotFound();
    $fixture['order']->update(['payment_method' => 'cod', 'payment_status' => 'cod_due']);
    uploadBankProof($this, $fixture)->assertStatus(409);
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(0);
});

test('invalid or oversized files and terminal orders are rejected', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture, file: UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml'))
        ->assertStatus(422);
    uploadBankProof($this, $fixture, file: UploadedFile::fake()->image('large.jpg')->size(6000))
        ->assertStatus(422);
    $fixture['order']->update(['status' => 'cancelled']);
    uploadBankProof($this, $fixture)->assertStatus(409);
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('web-bank-transfer-proofs'))->toBe([]);
});

test('staff verification requires proof permissions and exact received amount', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture)->assertCreated();
    $attempt = DB::table('web_bank_transfer_attempts')->sole();
    $base = '/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$attempt->id;
    $viewer = bankStaff($fixture, ['storefront.view-orders']);
    $this->actingAs($viewer)->get($base)->assertForbidden();
    $this->post($base.'/review', ['decision' => 'verified', 'received_amount' => '1250.00'])->assertForbidden();
    $viewer->givePermissionTo(Permission::findOrCreate('storefront.view-payment-proofs', 'web'));
    $this->get($base)->assertOk();
    $viewer->givePermissionTo(Permission::findOrCreate('storefront.verify-payments', 'web'));
    $this->post($base.'/review', ['decision' => 'verified', 'received_amount' => '1000.00'])->assertSessionHasErrors('received_amount');
    $this->post($base.'/review', ['decision' => 'verified', 'received_amount' => '1250.00'])->assertRedirect();
    expect($fixture['order']->fresh()->payment_status)->toBe('paid_verified');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_bank_transfer_decisions')->where('web_order_id', $fixture['order']->id)
        ->value('actor_user_id'))->toBe($viewer->id);
    $this->post($base.'/review', ['decision' => 'verified', 'received_amount' => '1250.00'])->assertSessionHasErrors();
});

test('underpayment stays unpaid and a later exact top-up can be verified', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture, details: ['amount_transferred' => '500.00'])->assertCreated();
    $staff = bankStaff($fixture, ['storefront.view-orders', 'storefront.view-payment-proofs', 'storefront.verify-payments']);
    $first = DB::table('web_bank_transfer_attempts')->first();
    $this->actingAs($staff)->post('/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$first->id.'/review',
        ['decision' => 'underpaid', 'received_amount' => '500.00', 'internal_note' => 'Only 500 received'])->assertRedirect();
    expect($fixture['order']->fresh()->payment_status)->toBe('underpaid');
    $tracked = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['credential']])->assertOk()
        ->assertJsonPath('data.payment_remaining', '750.00')->assertJsonPath('data.proof_submission_allowed', true);
    expect($tracked->getContent())->not->toContain('Only 500 received', 'evidence_path', 'actor_user_id');
    uploadBankProof($this, $fixture, details: ['transfer_reference' => 'BANK-REF-2', 'amount_transferred' => '750.00'])->assertCreated();
    $second = DB::table('web_bank_transfer_attempts')->orderByDesc('id')->first();
    $this->post('/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$second->id.'/review',
        ['decision' => 'verified', 'received_amount' => '750.00'])->assertRedirect();
    expect($fixture['order']->fresh()->payment_status)->toBe('paid_verified');
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(2);
    expect(DB::table('web_bank_transfer_decisions')->count())->toBe(2);
});

test('rejection requires reason and overpayment is recorded without marking paid', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture)->assertCreated();
    $staff = bankStaff($fixture, ['storefront.view-orders', 'storefront.view-payment-proofs', 'storefront.verify-payments']);
    $attempt = DB::table('web_bank_transfer_attempts')->sole();
    $url = '/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$attempt->id.'/review';
    $this->actingAs($staff)->post($url, ['decision' => 'rejected', 'received_amount' => '0.00'])->assertSessionHasErrors('internal_note');
    $this->post($url, ['decision' => 'verified', 'received_amount' => '1300.00'])->assertRedirect();
    expect($fixture['order']->fresh()->payment_status)->toBe('transfer_awaiting_verification');
    expect(DB::table('web_bank_transfer_decisions')->value('decision'))->toBe('overpayment_review');
});

test('rejected evidence remains auditable and a corrected proof can be submitted', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture)->assertCreated();
    $staff = bankStaff($fixture, ['storefront.view-orders', 'storefront.view-payment-proofs', 'storefront.verify-payments']);
    $first = DB::table('web_bank_transfer_attempts')->sole();
    $url = '/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$first->id.'/review';
    $this->actingAs($staff)->post($url, ['decision' => 'rejected', 'received_amount' => '0.00',
        'internal_note' => 'Reference does not match bank statement'])->assertRedirect();
    expect($fixture['order']->fresh()->payment_status)->toBe('rejected');
    uploadBankProof($this, $fixture, details: ['transfer_reference' => 'CORRECTED-REF'])->assertCreated();
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(2);
    expect(DB::table('web_bank_transfer_decisions')->where('decision', 'rejected')->count())->toBe(1);
});

test('unassigned staff cannot download or review evidence from another location', function () {
    $fixture = bankFixture($this);
    uploadBankProof($this, $fixture)->assertCreated();
    $attempt = DB::table('web_bank_transfer_attempts')->sole();
    $staff = User::factory()->create();
    foreach (['storefront.view-orders', 'storefront.view-payment-proofs', 'storefront.verify-payments'] as $name) {
        $staff->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $url = '/storefront/orders/'.$fixture['order']->reference.'/bank-proofs/'.$attempt->id;
    $this->actingAs($staff)->get($url)->assertNotFound();
    $this->post($url.'/review', ['decision' => 'verified', 'received_amount' => '1250.00'])->assertNotFound();
});
