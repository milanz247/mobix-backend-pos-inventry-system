<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\WebCart;
use App\Models\WebDeliveryArea;
use App\Models\WebOrderOffer;
use App\ProductCondition;
use App\ProductType;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontOrderOfferService
{
    public function __construct(private readonly StorefrontCatalogService $catalog, private readonly StorefrontCartDemandService $demand,
        private readonly WebFulfillmentPolicy $fulfillmentPolicy, private readonly WebPickupLocationService $pickupLocations,
        private readonly StorefrontSelectedDeviceService $selectedDevices, private readonly StorefrontOfferTermsService $offerTerms) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Business $business, WebCart $cart, array $input): array
    {
        $ttl = config('storefront.offer_ttl_minutes');
        if (! is_numeric($ttl) || filter_var($ttl, FILTER_VALIDATE_INT) === false || (int) $ttl < 1) {
            throw new StorefrontCartException('OFFER_NOT_CONFIGURED', 'Order-ready offers are not available yet.', 503);
        }

        return DB::transaction(function () use ($business, $cart, $input, $ttl): array {
            $cart = WebCart::query()->where('business_id', $business->id)->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'active' || $cart->expires_at->isPast()) {
                throw new StorefrontCartException('CART_EXPIRED', 'This cart has expired. Start a new cart.', 410);
            }
            $quote = $cart->quotes()->where('business_id', $business->id)->where('reference', $input['quote_reference'])->with('items')->first();
            if ($quote === null || $quote->status !== 'indicative' || $quote->expires_at->isPast() || $quote->currency !== $business->currency) {
                throw new StorefrontCartException('QUOTE_STALE', 'Request a fresh indicative quote before continuing.', 409);
            }
            $cartItems = $cart->items()->orderBy('serialized_device_id')->orderBy('id')->get();
            $quoted = $quote->items->map(fn ($line) => $line->product_id.':'.$line->product_variant_id.':'.$line->quantity)->sort()->values()->all();
            $current = $cartItems->map(fn ($line) => $line->product_id.':'.$line->product_variant_id.':'.$line->quantity)->sort()->values()->all();
            if ($current === [] || $quoted !== $current) {
                throw new StorefrontCartException('QUOTE_STALE', 'The cart has changed. Request a fresh indicative quote.', 409);
            }

            $mode = $this->fulfillmentPolicy->assertAllowed($input['payment_method'], $input['fulfillment_mode']);
            $area = null;
            $pickup = null;
            if ($mode->value === 'home_delivery') {
                $area = WebDeliveryArea::query()->where('business_id', $business->id)->where('code', $input['delivery_area_code'])->where('is_active', true)->first();
                if ($area === null || $area->currency !== $business->currency) {
                    throw new StorefrontCartException('DELIVERY_AREA_UNAVAILABLE', 'This delivery area is not supported.', 422);
                }
                $mappings = $area->mappings()->where('business_id', $business->id)->where('is_active', true)
                    ->whereHas('location', fn ($query) => $query->where('business_id', $business->id)->where('status', 'active'))
                    ->with('location')->orderBy('selection_priority')->orderBy('location_id')->get();
                if ($mappings->isEmpty()) {
                    throw new StorefrontCartException('DELIVERY_AREA_UNAVAILABLE', 'This delivery area is not supported.', 422);
                }
            } else {
                $pickup = $this->pickupLocations->resolve($business, $input['pickup_location_code']);
                if ($pickup === null) {
                    throw new StorefrontCartException('PICKUP_LOCATION_UNAVAILABLE', 'This pickup location is not available.', 422);
                }
                $mappings = collect([$pickup]);
            }

            $lines = [];
            foreach ($cartItems as $item) {
                $product = $this->catalog->products($business)->whereKey($item->product_id)->first();
                $variant = $product?->variants->firstWhere('id', $item->product_variant_id);
                if ($product === null || $variant === null || $variant->business_id !== $business->id) {
                    throw new StorefrontCartException('QUOTE_STALE', 'A product is no longer available. Request a fresh quote.', 409);
                }
                $device = $this->selectedDevices->resolve($business, $variant, $item, true, 'QUOTE_STALE');
                $quotedLine = $this->selectedDevices->quoteLine($quote->items, $item, $variant);
                if ($device !== null && $device->location_id !== $quotedLine->selected_location_id) {
                    throw new StorefrontCartException('QUOTE_STALE', 'A selected option changed. Request a fresh quote.', 409);
                }
                $price = $device === null ? $this->catalog->startingPrice($variant) : $item->selected_unit_price;
                if ($price === null || BigDecimal::of($price)->compareTo($quotedLine->unit_price) !== 0) {
                    throw new StorefrontCartException('QUOTE_STALE', 'A product price or availability has changed. Request a fresh quote.', 409);
                }
                $lines[] = ['item' => $item, 'product' => $product, 'variant' => $variant, 'device' => $device];
            }

            $selection = null;
            foreach ($mappings as $mapping) {
                $candidate = $this->evaluateLocation($business, $mapping->location, $lines);
                if ($candidate !== null) {
                    $selection = ['mapping' => $mapping, 'items' => $candidate];
                    break;
                }
            }
            if ($selection === null) {
                throw new StorefrontCartException($area === null ? 'PICKUP_LOCATION_UNAVAILABLE' : 'NO_SINGLE_LOCATION',
                    $area === null ? 'The selected pickup shop cannot currently fulfill the complete cart.' : 'No single eligible location can currently fulfill the complete cart.', 409);
            }

            $items = $selection['items'];
            $subtotal = BigDecimal::zero();
            foreach ($items as $item) {
                $subtotal = $subtotal->plus($item['line_total']);
            }
            $fee = $area === null ? '0.00' : (string) ($selection['mapping']->delivery_fee_override ?? $area->base_delivery_fee);
            $pickupDetails = $pickup === null ? null : $this->pickupLocations->publicSnapshot($pickup);
            $subtotal = $subtotal->toScale(2);
            $total = $subtotal->plus($fee)->toScale(2);
            $expiresAt = now()->addMinutes((int) $ttl);
            if ($quote->expires_at->isBefore($expiresAt)) {
                $expiresAt = $quote->expires_at;
            }
            $reference = (string) Str::ulid();
            $publicItems = array_map(fn (array $item): array => [
                'slug' => $item['product_slug'], 'name' => $item['product_name'], 'variant_name' => $item['variant_name'],
                'condition' => $item['condition_snapshot'], 'included_device_conditions' => $item['included_device_conditions'], 'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'], 'line_total' => $item['line_total'],
            ], $items);
            // Placeholder exists only inside this transaction; replaced after snapshot normalization.
            $digest = str_repeat('0', 64);
            $offer = WebOrderOffer::query()->create([
                'business_id' => $business->id, 'web_cart_id' => $cart->id, 'web_quote_id' => $quote->id,
                'delivery_area_id' => $area?->id, 'location_id' => $selection['mapping']->location_id,
                'delivery_area_code_snapshot' => $area?->code, 'delivery_area_name_snapshot' => $area?->name,
                'fulfillment_mode' => $mode->value,
                'pickup_location_code_snapshot' => $pickupDetails['code'] ?? null,
                'pickup_location_name_snapshot' => $pickupDetails['name'] ?? null,
                'pickup_location_address_snapshot' => $pickupDetails['address'] ?? null,
                'pickup_location_city_snapshot' => $pickupDetails['city'] ?? null,
                'reference' => $reference, 'principal_hash' => $cart->token_hash, 'status' => 'offered',
                'currency' => $business->currency, 'subtotal' => (string) $subtotal, 'discount_total' => '0.00',
                'delivery_fee' => $fee, 'total' => (string) $total, 'payment_method' => $input['payment_method'],
                'contact_snapshot' => $input['contact'], 'address_snapshot' => $input['address'] ?? [],
                'terms_digest' => $digest, 'terms_digest_version' => 2, 'expires_at' => $expiresAt,
            ]);
            foreach ($items as $index => $item) {
                $offer->items()->create($item + ['business_id' => $business->id, 'line_number' => $index + 1]);
            }

            // Read database-normalized snapshots before hashing so acceptance can recompute exactly.
            $offer->refresh();
            $digest = $this->offerTerms->digest($offer, $offer->items()->orderBy('line_number')->get());
            $offer->update(['terms_digest' => $digest]);

            return ['reference' => $reference, 'expires_at' => $expiresAt->toIso8601String(), 'currency' => $business->currency,
                'fulfillment_mode' => $mode->value,
                'delivery_area' => $area === null ? null : ['code' => $area->code, 'name' => $area->name],
                'pickup_location' => $pickupDetails,
                'items' => $publicItems,
                'subtotal' => (string) $subtotal, 'discount_total' => '0.00', 'delivery_fee' => $fee,
                'total' => (string) $total, 'payment_method' => $input['payment_method'], 'terms_digest' => $digest,
                'total_is_final' => true, 'delivery_included' => true, 'inventory_reserved' => false,
                'requires_customer_acceptance' => true];
        });
    }

    /** @param list<array{item: mixed, product: mixed, variant: ProductVariant, device: SerializedDevice|null}> $lines
     * @return list<array<string, mixed>>|null
     */
    private function evaluateLocation(Business $business, Location $location, array $lines): ?array
    {
        $demandLines = array_map(fn (array $line): array => [
            'product' => $line['product'], 'variant' => $line['variant'], 'quantity' => $line['item']->quantity,
        ], $lines);
        if (! $this->demand->canFulfill($business, $location, $demandLines)) {
            return null;
        }
        $pinnedIds = [];
        foreach ($lines as $line) {
            if ($line['device'] !== null) {
                if ($line['device']->location_id !== $location->id || isset($pinnedIds[$line['device']->id])) {
                    return null;
                }
                $pinnedIds[$line['device']->id] = true;
            }
        }
        $demand = [];
        $variants = [];
        $componentsByLine = [];
        foreach ($lines as $line) {
            $variant = $line['variant'];
            $components = $line['product']->product_type === ProductType::Combo
                ? $variant->bundleComponents()->with('componentVariant.product')->get()
                : collect();
            if ($line['product']->product_type === ProductType::Combo && $components->isEmpty()) {
                return null;
            }
            $requirements = $components->isEmpty()
                ? [['variant' => $variant, 'quantity' => (string) $line['item']->quantity]]
                : $components->map(fn ($component) => ['variant' => $component->componentVariant,
                    'quantity' => (string) BigDecimal::of($component->quantity)->multipliedBy($line['item']->quantity)])->all();
            foreach ($requirements as $requirement) {
                $component = $requirement['variant'];
                if ($component === null || $component->business_id !== $business->id || $component->status->value !== 'active' ||
                    $component->product->status->value !== 'active' || in_array($component->product->product_type, [ProductType::Combo, ProductType::Service], true)) {
                    return null;
                }
                if ($line['device'] !== null) {
                    continue;
                }
                $id = $component->id;
                $demand[$id] = ($demand[$id] ?? BigDecimal::zero())->plus($requirement['quantity']);
                $variants[$id] = $component;
            }
            $componentsByLine[] = $requirements;
        }

        $devicesByVariant = [];
        foreach ($demand as $id => $quantity) {
            $variant = $variants[$id];
            if ($variant->tracking_mode === VariantTrackingMode::Serialized) {
                if ($quantity->getScale() > 0 && $quantity->remainder('1')->isGreaterThan('0')) {
                    return null;
                }
                $devices = $variant->serializedDevices()->where('business_id', $business->id)->where('location_id', $location->id)
                    ->where('status', 'in_stock')->whereNotIn('id', array_keys($pinnedIds))
                    ->when($variant->condition === ProductCondition::Used, fn ($query) => $query->where('condition', 'used'))
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('web_order_reservations as r')
                        ->whereColumn('r.active_device_id', 'serialized_devices.id')->where('r.business_id', $business->id)->where('r.status', 'active'))
                    ->orderByRaw('COALESCE(unit_selling_price, ?) ASC', [$variant->selling_price])->orderBy('id')
                    ->limit((int) (string) $quantity)->get();
                if ($devices->count() < (int) (string) $quantity) {
                    return null;
                }
                $devicesByVariant[$id] = $devices->values();
            }
        }

        $result = [];
        foreach ($lines as $lineIndex => $line) {
            $variant = $line['variant'];
            $allocations = [];
            $includedConditions = [];
            foreach ($componentsByLine[$lineIndex] as $requirement) {
                $component = $requirement['variant'];
                $allocation = ['variant_id' => $component->id, 'quantity' => $requirement['quantity']];
                if ($component->tracking_mode === VariantTrackingMode::Serialized) {
                    $count = (int) $requirement['quantity'];
                    $allocation['device_ids'] = $line['device'] !== null
                        ? [$line['device']->id] : $devicesByVariant[$component->id]->splice(0, $count)->pluck('id')->all();
                    if ($line['product']->product_type === ProductType::Combo) {
                        $allocation['device_prices'] = [];
                        foreach ($allocation['device_ids'] as $selectedId) {
                            $selected = $component->serializedDevices()->whereKey($selectedId)->firstOrFail();
                            $allocation['device_prices'][] = (string) ($selected->unit_selling_price ?? $component->selling_price);
                            $includedConditions[] = ['variant_name' => $component->name, 'condition' => $selected->condition->value];
                        }
                    }
                }
                $allocations[] = $allocation;
            }
            $direct = $line['product']->product_type !== ProductType::Combo && $variant->tracking_mode === VariantTrackingMode::Serialized;
            $devices = $direct ? ($allocations[0]['device_ids'] ?? []) : [];
            $count = $direct ? count($devices) : 1;
            for ($i = 0; $i < $count; $i++) {
                $device = $direct ? $variant->serializedDevices()->whereKey($devices[$i])->first() : null;
                $price = $device !== null ? (string) ($device->unit_selling_price ?? $variant->selling_price) : (string) $variant->selling_price;
                $quantity = $direct ? 1 : $line['item']->quantity;
                $result[] = [
                    'source_cart_item_key' => $line['item']->public_key, 'product_id' => $line['product']->id,
                    'product_variant_id' => $variant->id, 'serialized_device_id' => $device?->id,
                    'private_allocation' => $direct ? [['variant_id' => $variant->id, 'quantity' => '1', 'device_ids' => [$device->id]]] : $allocations,
                    'product_slug' => $line['product']->webListing->slug,
                    'product_name' => $line['product']->webListing->public_title ?: $line['product']->name,
                    'variant_name' => $variant->name, 'condition_snapshot' => $device === null ? $variant->condition?->value : $device->condition->value,
                    'included_device_conditions' => $includedConditions === [] ? null : $includedConditions,
                    'quantity' => $quantity, 'unit_price' => BigDecimal::of($price)->toScale(2)->__toString(),
                    'line_total' => BigDecimal::of($price)->multipliedBy($quantity)->toScale(2)->__toString(),
                ];
            }
        }

        return $result;
    }
}
