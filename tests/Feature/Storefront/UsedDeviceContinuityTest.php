<?php

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\WebOrder;
use App\Models\WebOrderOffer;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\Models\WebQuote;
use App\Models\WebQuoteItem;
use App\Services\StorefrontOfferTermsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/UsedDeviceContinuityFixture.php';

test('selected price and source identity survive quote offer acceptance and retry privately', function (bool $authenticated) {
    $f = usedContinuityFixture($this, $authenticated);
    $cartLine = $f['cart']->items()->sole();
    expect($cartLine->selected_unit_price)->toBe('1200.00');
    $quote = $this->postJson('/api/storefront/v1/quotes', [], $f['headers'])->assertOk()
        ->assertJsonPath('data.total', '1200.00')->assertJsonPath('data.inventory_reserved', false);
    $line = WebQuoteItem::query()->sole();
    expect($line->source_cart_item_key)->toBe($cartLine->public_key)
        ->and($line->serialized_device_id)->toBe($f['device']->id)
        ->and($line->selected_location_id)->toBe($f['location']->id)
        ->and($line->selected_condition)->toBe('used')->and($line->unit_price)->toBe('1200.00');
    $offer = usedContinuityOffer($this, $f, $quote->json('data.reference'))->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '1200.00')->assertJsonPath('data.inventory_reserved', false);
    $saved = WebOrderOffer::query()->sole();
    expect($saved->items()->sole()->serialized_device_id)->toBe($f['device']->id)
        ->and($saved->terms_digest)->toBe(app(StorefrontOfferTermsService::class)->digest($saved, $saved->items))
        ->and(DB::table('web_order_reservations')->count())->toBe(0);
    $key = (string) Str::uuid();
    $accepted = usedContinuityAcceptance($this, $f, $offer, $key)->assertCreated();
    $retry = usedContinuityAcceptance($this, $f, $offer, $key)->assertOk()
        ->assertJsonPath('data.reference', $accepted->json('data.reference'));
    $reservation = DB::table('web_order_reservations')->sole();
    expect($reservation->serialized_device_id)->toBe($f['device']->id)
        ->and($reservation->active_device_id)->toBe($f['device']->id)
        ->and(DB::table('web_order_reservations')->count())->toBe(1);
    $snapshot = json_decode(DB::table('web_order_items')->sole()->private_inventory_snapshot, true, 512, JSON_THROW_ON_ERROR);
    expect($snapshot[0]['device_id'])->toBe($f['device']->id)->and($snapshot[0]['unit_price'])->toBe('1200.00');
    foreach ([$quote, $offer, $accepted, $retry] as $response) {
        expect($response->getContent())->not->toContain('serialized_device_id', 'selected_location_id', 'source_cart_item_key',
            'private_allocation', 'private_inventory_snapshot', 'reservation_id', 'supplier', 'unit_cost',
            $f['device']->imei, $f['device']->serial_number);
    }
})->with([false, true]);

test('legacy lines without complete selection snapshots never fall back to starting prices', function (string $field) {
    $f = usedContinuityFixture($this);
    $f['cart']->items()->update([$field => null]);
    $this->getJson('/api/storefront/v1/cart', $f['headers'])->assertOk()
        ->assertJsonPath('data.can_quote', false)->assertJsonPath('data.items.0.unit_price', null)
        ->assertJsonPath('data.indicative_subtotal', null);
    $this->postJson('/api/storefront/v1/quotes', [], $f['headers'])->assertStatus(409)
        ->assertJsonPath('error.code', 'DEVICE_SELECTION_UNAVAILABLE');
    expect(WebQuote::query()->count())->toBe(0)->and(DB::table('web_order_reservations')->count())->toBe(0);
})->with(['serialized_device_id', 'selected_unit_price']);

test('quote rejects stale selected device without choosing the cheaper replacement', function (string $change) {
    $f = usedContinuityFixture($this);
    match ($change) {
        'price' => $f['device']->update(['unit_selling_price' => '1250.00']),
        'condition' => $f['device']->update(['condition' => 'refurbished']),
        'status' => $f['device']->update(['status' => 'sold']),
        'location' => $f['location']->update(['status' => 'inactive']),
        'variant' => $f['variant']->update(['condition' => 'new']),
    };
    $this->postJson('/api/storefront/v1/quotes', [], $f['headers'])->assertStatus(409);
    expect(WebQuote::query()->count())->toBe(0)->and(DB::table('web_order_reservations')->count())->toBe(0);
})->with(['price', 'condition', 'status', 'location', 'variant']);

test('two devices of one variant stay distinct and cannot be added twice', function () {
    $f = usedContinuityFixture($this);
    $selection = selectFixtureDevice($this, $f['product'], $f['variant'], $f['cheaper']);
    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $f['product']->id, 'variant_id' => $f['variant']->id, 'quantity' => 1,
        'device_selection_token' => $selection,
    ], $f['headers'])->assertCreated()->assertJsonCount(2, 'data.items');
    $again = selectFixtureDevice($this, $f['product'], $f['variant'], $f['device']);
    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $f['product']->id, 'variant_id' => $f['variant']->id, 'quantity' => 1,
        'device_selection_token' => $again,
    ], $f['headers'])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_ALREADY_IN_CART');
    $offer = usedContinuityOffer($this, $f)->assertCreated()->assertJsonPath('data.subtotal', '1900.00');
    expect(WebQuoteItem::query()->pluck('source_cart_item_key')->unique()->count())->toBe(2);
    $saved = WebOrderOffer::query()->sole();
    foreach ($f['cart']->items as $cartLine) {
        $offered = $saved->items()->where('source_cart_item_key', $cartLine->public_key)->sole();
        expect($offered->serialized_device_id)->toBe($cartLine->serialized_device_id)
            ->and($offered->unit_price)->toBe($cartLine->selected_unit_price);
    }
    usedContinuityAcceptance($this, $f, $offer, (string) Str::uuid())->assertCreated();
    expect(DB::table('web_order_reservations')->pluck('active_device_id')->sort()->values()->all())
        ->toBe(collect([$f['device']->id, $f['cheaper']->id])->sort()->values()->all());
});

test('offer rejects device outside chosen location or with a changed quote snapshot', function (string $change) {
    $f = usedContinuityFixture($this);
    $quote = $this->postJson('/api/storefront/v1/quotes', [], $f['headers'])->assertOk();
    match ($change) {
        'location' => $f['device']->update(['location_id' => Location::factory()->for($f['business'])->create()->id]),
        'price' => WebQuoteItem::query()->update(['unit_price' => '700.00', 'line_total' => '700.00']),
        'identity' => WebQuoteItem::query()->update(['serialized_device_id' => $f['cheaper']->id]),
        'source' => WebQuoteItem::query()->update(['source_cart_item_key' => (string) Str::ulid()]),
        'legacy' => WebQuoteItem::query()->update(['source_cart_item_key' => null, 'serialized_device_id' => null]),
        'cart' => $f['cart']->items()->update(['serialized_device_id' => $f['cheaper']->id, 'selected_unit_price' => '700.00']),
    };
    usedContinuityOffer($this, $f, $quote->json('data.reference'))->assertStatus(409);
    expect(WebOrderOffer::query()->count())->toBe(0)->and(DB::table('web_order_reservations')->count())->toBe(0);
})->with(['location', 'price', 'identity', 'source', 'legacy', 'cart']);

test('acceptance rejects changed offer quote cart and device and rolls back all writes', function (string $change) {
    $f = usedContinuityFixture($this);
    $offer = usedContinuityOffer($this, $f)->assertCreated();
    $saved = WebOrderOffer::query()->sole();
    match ($change) {
        'allocation' => $saved->items()->update(['serialized_device_id' => $f['cheaper']->id,
            'private_allocation' => [['variant_id' => $f['variant']->id, 'quantity' => '1', 'device_ids' => [$f['cheaper']->id]]]]),
        'offer_price' => $saved->items()->update(['unit_price' => '700.00', 'line_total' => '700.00']),
        'digest' => $saved->update(['terms_digest' => str_repeat('a', 64)]),
        'quote' => WebQuoteItem::query()->update(['serialized_device_id' => $f['cheaper']->id]),
        'legacy' => WebQuoteItem::query()->update(['serialized_device_id' => null, 'source_cart_item_key' => null]),
        'legacy_digest' => $saved->update(['terms_digest_version' => null]),
        'cart' => $f['cart']->items()->update(['serialized_device_id' => $f['cheaper']->id, 'selected_unit_price' => '700.00']),
        'price' => $f['device']->update(['unit_selling_price' => '1250.00']),
        'condition' => $f['device']->update(['condition' => 'refurbished']),
        'status' => $f['device']->update(['status' => 'sold']),
        'location' => $f['device']->update(['location_id' => Location::factory()->for($f['business'])->create()->id]),
        'inactive' => $f['location']->update(['status' => 'inactive']),
    };
    usedContinuityAcceptance($this, $f, $offer, (string) Str::uuid())->assertStatus(409);
    expect(WebOrder::query()->count())->toBe(0)->and(DB::table('web_order_reservations')->count())->toBe(0)
        ->and(DB::table('web_order_items')->count())->toBe(0)->and(DB::table('web_order_payments')->count())->toBe(0);
})->with(['allocation', 'offer_price', 'digest', 'quote', 'legacy', 'legacy_digest', 'cart', 'price', 'condition', 'status', 'location', 'inactive']);

test('reservation created after advisory selection prevents competing quote offer and acceptance', function () {
    $f = usedContinuityFixture($this);
    $first = usedContinuityOffer($this, $f)->assertCreated();
    $selection = selectFixtureDevice($this, $f['product'], $f['variant'], $f['device']);
    $added = $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $f['product']->id,
        'variant_id' => $f['variant']->id, 'quantity' => 1, 'device_selection_token' => $selection])->assertCreated();
    $other = [...$f, 'headers' => ['X-Storefront-Cart-Token' => $added->headers->get('X-Storefront-Cart-Token')]];
    $quote = $this->postJson('/api/storefront/v1/quotes', [], $other['headers'])->assertOk();
    $competing = usedContinuityOffer($this, $other, $quote->json('data.reference'))->assertCreated();
    // Even a valid outstanding selection token is advisory until acceptance wins the lock.
    $pending = selectFixtureDevice($this, $f['product'], $f['variant'], $f['device']);
    usedContinuityAcceptance($this, $f, $first, (string) Str::uuid())->assertCreated();
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $f['product']->id,
        'variant_id' => $f['variant']->id, 'quantity' => 1, 'device_selection_token' => $pending])->assertUnprocessable();
    $this->postJson('/api/storefront/v1/quotes', [], $other['headers'])->assertStatus(409);
    usedContinuityOffer($this, $other, $quote->json('data.reference'))->assertStatus(409);
    usedContinuityAcceptance($this, $other, $competing, (string) Str::uuid())->assertStatus(409);
    expect(WebOrder::query()->count())->toBe(1)->and(DB::table('web_order_reservations')->count())->toBe(1);
});

test('ordinary phone accessory spare part and combo acceptance paths remain supported', function (string $kind) {
    $f = usedContinuityFixture($this);
    $this->deleteJson('/api/storefront/v1/cart', [], $f['headers'])->assertOk();
    $product = Product::factory()->for($f['business'])->for($f['category'])->create([
        'name' => $kind, 'product_type' => $kind === 'combo' ? 'combo' : ($kind === 'new_phone' ? 'serialized' : 'simple'),
    ]);
    $variant = ProductVariant::factory()->for($f['business'])->for($product)->create([
        'condition' => 'new', 'selling_price' => '400.00',
        'tracking_mode' => $kind === 'new_phone' ? 'serialized' : 'quantity_based',
    ]);
    WebProductListing::query()->create(['business_id' => $f['business']->id,
        'product_id' => $product->id, 'slug' => 'ordinary-control', 'status' => 'published']);
    $stockVariant = $variant;
    if ($kind === 'combo') {
        $componentProduct = Product::factory()->for($f['business'])->for($f['category'])->create();
        $stockVariant = ProductVariant::factory()->for($f['business'])->for($componentProduct)->create();
        $variant->bundleComponents()->create(['business_id' => $f['business']->id,
            'component_variant_id' => $stockVariant->id, 'quantity' => '2.000']);
    }
    if ($kind === 'new_phone') {
        SerializedDevice::factory()->for($f['location'])->for($variant, 'variant')->create([
            'business_id' => $f['business']->id, 'condition' => 'new', 'unit_selling_price' => '400.00',
        ]);
    } else {
        InventoryStock::factory()->for($f['business'])->for($f['location'])->for($stockVariant, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
    }
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1], $f['headers'])->assertCreated();
    $offer = usedContinuityOffer($this, $f)->assertCreated()->assertJsonPath('data.subtotal', '400.00');
    usedContinuityAcceptance($this, $f, $offer, (string) Str::uuid())->assertCreated();
    $reservation = DB::table('web_order_reservations')->sole();
    expect($reservation->component_variant_id)->toBe($stockVariant->id)
        ->and($reservation->quantity)->toBe($kind === 'combo' ? '2.000' : '1.000');
})->with(['new_phone', 'accessory', 'spare_part', 'combo']);

test('a combo sharing a used variant cannot consume the individually selected device', function () {
    $f = usedContinuityFixture($this);
    $bundleProduct = Product::factory()->for($f['business'])->for($f['category'])->combo()->create();
    $bundle = ProductVariant::factory()->for($f['business'])->for($bundleProduct)->create([
        'selling_price' => '2000.00',
    ]);
    $bundle->bundleComponents()->create(['business_id' => $f['business']->id,
        'component_variant_id' => $f['variant']->id, 'quantity' => '1.000']);
    WebProductListing::query()->create(['business_id' => $f['business']->id,
        'product_id' => $bundleProduct->id, 'slug' => 'used-combo-control', 'status' => 'published']);
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $bundleProduct->id,
        'variant_id' => $bundle->id, 'quantity' => 1], $f['headers'])->assertCreated();
    $offer = usedContinuityOffer($this, $f)->assertCreated();
    $saved = WebOrderOffer::query()->sole();
    $direct = $saved->items()->where('product_variant_id', $f['variant']->id)->sole();
    $combo = $saved->items()->where('product_variant_id', $bundle->id)->sole();
    expect($direct->serialized_device_id)->toBe($f['device']->id)
        ->and($combo->private_allocation[0]['device_ids'])->toBe([$f['cheaper']->id])
        ->and(DB::table('web_order_reservations')->count())->toBe(0);
    usedContinuityAcceptance($this, $f, $offer, (string) Str::uuid())->assertCreated();
    expect(DB::table('web_order_reservations')->count())->toBe(2);
});

test('a different pickup shop cannot replace the selected phone with its local cheaper device', function () {
    $f = usedContinuityFixture($this);
    $quote = $this->postJson('/api/storefront/v1/quotes', [], $f['headers'])->assertOk();
    $otherShop = Location::factory()->for($f['business'])->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $f['business']->id,
        'location_id' => $otherShop->id, 'public_code' => $otherShop->code, 'is_active' => true]);
    $f['cheaper']->update(['location_id' => $otherShop->id]);
    usedContinuityOffer($this, [...$f, 'location' => $otherShop], $quote->json('data.reference'))
        ->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_LOCATION_UNAVAILABLE');
    expect(WebOrderOffer::query()->count())->toBe(0)->and(DB::table('web_order_reservations')->count())->toBe(0);
});

test('snapshot migration rollback refuses to discard live selected-device evidence', function () {
    usedContinuityFixture($this);
    $migration = require database_path('migrations/2026_11_04_000003_add_selected_device_quote_snapshots.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasColumn('web_cart_items', 'selected_unit_price'))->toBeTrue();
});
