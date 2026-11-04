<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\WebCart;
use App\Models\WebDeliveryArea;
use App\Models\WebOrder;
use App\Models\WebOrderOffer;
use App\Models\WebOrderPayment;
use App\Models\WebQuote;
use App\ProductType;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontOrderSubmissionService
{
    public function __construct(
        private readonly StorefrontCatalogService $catalog,
        private readonly StorefrontCartDemandService $demand,
        private readonly WebReservationService $reservations,
        private readonly WebFulfillmentPolicy $fulfillmentPolicy,
        private readonly WebPickupLocationService $pickupLocations,
        private readonly StorefrontSelectedDeviceService $selectedDeviceContinuity,
        private readonly StorefrontOfferTermsService $offerTerms,
    ) {}

    /** @return array{data: array<string, mixed>, credential: string, replayed: bool} */
    public function submit(Business $business, WebCart $cart, string $cartToken, string $submissionKey, string $offerReference, string $acceptedDigest): array
    {
        $ttl = config('storefront.reservation_ttl_minutes');
        $secret = config('storefront.order_access_key');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new StorefrontCartException('ORDER_CONFIGURATION_REQUIRED', 'Order submission is not configured.', 503);
        }
        $principalHash = hash('sha256', $cartToken);
        $payloadHash = hash('sha256', json_encode([
            'principal' => $principalHash, 'offer' => $offerReference, 'digest' => $acceptedDigest, 'accepted' => true,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($business, $cart, $cartToken, $submissionKey, $offerReference, $acceptedDigest, $principalHash, $payloadHash, $ttl, $secret): array {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing = WebOrder::query()->where('business_id', $business->id)->where('submission_key', $submissionKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->guest_principal_hash, $principalHash) || ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new StorefrontCartException('IDEMPOTENCY_CONFLICT', 'This submission key was already used for different terms.', 409);
                }

                return $this->result($existing, $cartToken, $secret, true);
            }
            if (filter_var($ttl, FILTER_VALIDATE_INT) === false || (int) $ttl < 1) {
                throw new StorefrontCartException('ORDER_CONFIGURATION_REQUIRED', 'Order submission is not configured.', 503);
            }

            $offer = WebOrderOffer::query()->where('business_id', $business->id)->where('reference', $offerReference)->lockForUpdate()->first();
            if ($offer === null || $offer->web_cart_id !== $cart->id || ! hash_equals((string) $offer->principal_hash, $principalHash)) {
                throw new StorefrontCartException('OFFER_NOT_FOUND', 'The offer could not be found for this cart.', 404);
            }
            $lockedCart = WebCart::query()->where('business_id', $business->id)->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $quote = WebQuote::query()->where('business_id', $business->id)->where('web_cart_id', $lockedCart->id)
                ->whereKey($offer->web_quote_id)->lockForUpdate()->first();
            if ($quote === null || $offer->status !== 'offered' || WebOrder::query()->where('web_order_offer_id', $offer->id)->exists()) {
                throw new StorefrontCartException('OFFER_UNAVAILABLE', 'Request and accept a fresh offer.', 409);
            }
            if ($lockedCart->status !== 'active' || $lockedCart->expires_at->isPast() ||
                $offer->expires_at->isPast() || $quote->expires_at->isPast()) {
                throw new StorefrontCartException('OFFER_EXPIRED', 'This offer has expired. Request a fresh offer.', 410);
            }
            if ($quote->status !== 'indicative' || $offer->currency !== $business->currency || $quote->currency !== $business->currency ||
                ! hash_equals((string) $offer->terms_digest, $acceptedDigest) || ! hash_equals((string) $lockedCart->token_hash, $principalHash)) {
                throw new StorefrontCartException('OFFER_STALE', 'The accepted offer no longer matches this cart.', 409);
            }
            $mode = $this->fulfillmentPolicy->assertAllowed((string) $offer->payment_method, $offer->fulfillment_mode);
            $deadlineConfig = $offer->payment_method === 'cod'
                ? config('storefront.cod_confirmation_ttl_minutes') : config('storefront.bank_proof_ttl_minutes');
            if (filter_var($deadlineConfig, FILTER_VALIDATE_INT) === false || (int) $deadlineConfig < 1
                || (int) $deadlineConfig > (int) $ttl) {
                throw new StorefrontCartException('ORDER_CONFIGURATION_REQUIRED', 'The order deadline is not configured.', 503);
            }

            $location = Location::query()->where('business_id', $business->id)->where('status', 'active')->find($offer->location_id);
            if ($mode->value === 'home_delivery') {
                $area = WebDeliveryArea::query()->where('business_id', $business->id)->where('is_active', true)->find($offer->delivery_area_id);
                $mapping = $area?->mappings()->where('business_id', $business->id)->where('location_id', $offer->location_id)->where('is_active', true)->first();
                if ($location === null || $area === null || $mapping === null || $area->currency !== $business->currency ||
                    $area->code !== $offer->delivery_area_code_snapshot || $area->name !== $offer->delivery_area_name_snapshot ||
                    $offer->pickup_location_code_snapshot !== null ||
                    BigDecimal::of((string) ($mapping->delivery_fee_override ?? $area->base_delivery_fee))->compareTo((string) $offer->delivery_fee) !== 0) {
                    throw new StorefrontCartException('OFFER_STALE', 'Delivery terms changed. Request a fresh offer.', 409);
                }
            } else {
                $pickup = $this->pickupLocations->resolve($business, (string) $offer->pickup_location_code_snapshot, true);
                $pickupSnapshot = $pickup === null ? null : $this->pickupLocations->publicSnapshot($pickup);
                if ($location === null || $pickup === null || $pickup->location_id !== $location->id ||
                    $offer->delivery_area_id !== null || $offer->delivery_area_code_snapshot !== null ||
                    $pickupSnapshot['code'] !== $offer->pickup_location_code_snapshot ||
                    $pickupSnapshot['name'] !== $offer->pickup_location_name_snapshot ||
                    $pickupSnapshot['address'] !== $offer->pickup_location_address_snapshot ||
                    $pickupSnapshot['city'] !== $offer->pickup_location_city_snapshot ||
                    BigDecimal::of((string) $offer->delivery_fee)->compareTo('0.00') !== 0) {
                    throw new StorefrontCartException('OFFER_STALE', 'Pickup terms changed. Request a fresh offer.', 409);
                }
            }

            $cartItems = $lockedCart->items()->orderBy('id')->get();
            $offerItems = $offer->items()->orderBy('line_number')->get();
            $quoteItems = $quote->items()->get();
            if ($cartItems->isEmpty() || $offerItems->isEmpty() || $quoteItems->count() !== $cartItems->count()) {
                throw new StorefrontCartException('OFFER_STALE', 'Cart contents changed. Request a fresh offer.', 409);
            }
            // Legacy ordinary offers retain their original digest contract. Used offers require version 2.
            if (($offer->terms_digest_version !== null && $offer->terms_digest_version !== 2)
                || ($offer->terms_digest_version === 2
                    && ! hash_equals((string) $offer->terms_digest, $this->offerTerms->digest($offer, $offerItems)))) {
                throw new StorefrontCartException('OFFER_STALE', 'The accepted terms changed. Request a fresh offer.', 409);
            }
            $offeredByCartKey = $offerItems->groupBy('source_cart_item_key');
            if ($offeredByCartKey->count() !== $cartItems->count()) {
                throw new StorefrontCartException('OFFER_STALE', 'Cart contents changed. Request a fresh offer.', 409);
            }

            $catalogLines = [];
            $itemsForOrder = [];
            $allocations = [];
            $quantityDemand = [];
            $selectedDevices = [];
            $subtotal = BigDecimal::zero();
            foreach ($cartItems as $cartItem) {
                $product = $this->catalog->products($business)->whereKey($cartItem->product_id)->first();
                $variant = $product?->variants->firstWhere('id', $cartItem->product_variant_id);
                $quoted = $variant === null ? null : $this->selectedDeviceContinuity->quoteLine($quoteItems, $cartItem, $variant, 'OFFER_STALE');
                $offered = $offeredByCartKey->get($cartItem->public_key);
                if ($product === null || $variant === null || $quoted === null || $offered === null ||
                    $quoted->product_id !== $product->id || (int) $quoted->quantity !== $cartItem->quantity ||
                    $offered->sum('quantity') !== $cartItem->quantity) {
                    throw new StorefrontCartException('OFFER_STALE', 'A product or cart line changed. Request a fresh offer.', 409);
                }
                $selectedDevice = $this->selectedDeviceContinuity->resolve($business, $variant, $cartItem, false, 'DEVICE_UNAVAILABLE');
                if ($selectedDevice !== null && ($offer->terms_digest_version !== 2 || $selectedDevice->location_id !== $location->id
                    || $quoted->selected_location_id !== $location->id || $offered->count() !== 1)) {
                    throw new StorefrontCartException('OFFER_STALE', 'A selected option changed. Request a fresh offer.', 409);
                }
                $catalogLines[] = ['product' => $product, 'variant' => $variant, 'quantity' => $cartItem->quantity];
                foreach ($offered as $offeredItem) {
                    if ($selectedDevice !== null && ($offeredItem->serialized_device_id !== $cartItem->serialized_device_id
                        || $offeredItem->condition_snapshot !== $quoted->selected_condition
                        || (string) $offeredItem->unit_price !== $quoted->unit_price)) {
                        throw new StorefrontCartException('OFFER_STALE', 'A selected option changed. Request a fresh offer.', 409);
                    }
                    $this->validateOfferLine($business, $offer, $offeredItem, $product, $variant, $cartItem->public_key);
                    $lineAllocations = $this->validateAllocations($business, $offeredItem, $product, $variant);
                    foreach ($lineAllocations as $allocation) {
                        $allocations[] = ['order_line' => count($itemsForOrder), ...$allocation];
                        if ($allocation['device_id'] !== null) {
                            if (isset($selectedDevices[$allocation['device_id']])) {
                                throw new StorefrontCartException('OFFER_STALE', 'A device was selected more than once.', 409);
                            }
                            $selectedDevices[$allocation['device_id']] = $allocation;
                        } else {
                            $id = $allocation['variant_id'];
                            $quantityDemand[$id] = ($quantityDemand[$id] ?? BigDecimal::zero())->plus($allocation['quantity']);
                        }
                    }
                    $itemsForOrder[] = $offeredItem;
                    $subtotal = $subtotal->plus((string) $offeredItem->line_total);
                }
            }

            $subtotal = $subtotal->toScale(2);
            if ($subtotal->compareTo((string) $offer->subtotal) !== 0 || BigDecimal::of((string) $offer->discount_total)->compareTo('0') !== 0 ||
                $subtotal->plus((string) $offer->delivery_fee)->compareTo((string) $offer->total) !== 0 ||
                ! $this->demand->canFulfill($business, $location, $catalogLines)) {
                throw new StorefrontCartException('OFFER_STALE', 'Price or availability changed. Request a fresh offer.', 409);
            }

            ksort($quantityDemand, SORT_NUMERIC);
            foreach ($quantityDemand as $variantId => $quantity) {
                $stock = InventoryStock::query()->where('business_id', $business->id)->where('location_id', $location->id)
                    ->where('product_variant_id', $variantId)->lockForUpdate()->first();
                if ($stock === null || BigDecimal::of($this->reservations->freeQuantity((string) $stock->quantity_on_hand, $business->id, $location->id, $variantId))->isLessThan($quantity)) {
                    throw new StorefrontCartException('STOCK_UNAVAILABLE', 'Stock changed. Request a fresh offer.', 409);
                }
            }
            ksort($selectedDevices, SORT_NUMERIC);
            foreach ($selectedDevices as $deviceId => $allocation) {
                $device = SerializedDevice::query()->where('business_id', $business->id)->lockForUpdate()->find($deviceId);
                if ($device === null || $device->location_id !== $location->id || $device->product_variant_id !== $allocation['variant_id'] ||
                    $device->status->value !== 'in_stock' || $this->reservations->hasActiveDevice($business->id, $deviceId) ||
                    $device->condition->value !== $allocation['condition'] ||
                    BigDecimal::of((string) ($device->unit_selling_price ?? $allocation['fallback_price']))->compareTo($allocation['exact_price']) !== 0) {
                    throw new StorefrontCartException('DEVICE_UNAVAILABLE', 'The selected device changed. Request and accept a fresh offer.', 409);
                }
            }
            // The business mutex protects against participating POS writers. Recheck after physical locks.
            if (! $this->demand->canFulfill($business, $location, $catalogLines)) {
                throw new StorefrontCartException('STOCK_UNAVAILABLE', 'Stock changed. Request a fresh offer.', 409);
            }

            $status = 'pending_confirmation';
            $paymentStatus = $offer->payment_method === 'cod' ? 'cod_due' : 'transfer_awaiting_proof';
            $reference = (string) Str::ulid();
            $credential = $this->credential($business->id, $reference, $cartToken, $secret);
            $submittedAt = now();
            $productTaxTotal = BigDecimal::zero();
            foreach ($itemsForOrder as $item) {
                $productTaxTotal = $productTaxTotal->plus($this->includedProductTax((string) $item->line_total, (string) $business->tax_rate));
            }
            $reservationExpiry = $submittedAt->copy()->addMinutes((int) $ttl);
            $policyDeadline = $submittedAt->copy()->addMinutes((int) $deadlineConfig);
            $order = WebOrder::query()->create([
                'business_id' => $business->id, 'location_id' => $location->id, 'web_cart_id' => $lockedCart->id,
                'web_quote_id' => $quote->id, 'web_order_offer_id' => $offer->id, 'reference' => $reference,
                'submission_key' => $submissionKey, 'payload_hash' => $payloadHash, 'guest_principal_hash' => $principalHash,
                'guest_access_token_hash' => hash('sha256', $credential), 'accepted_terms_digest' => $acceptedDigest,
                'offer_reference_snapshot' => $offer->reference, 'status' => $status,
                'payment_method' => $offer->payment_method, 'payment_status' => $paymentStatus,
                'fulfillment_mode' => $mode->value,
                'currency' => $offer->currency, 'subtotal' => $offer->subtotal, 'discount_total' => $offer->discount_total,
                'product_tax_rate_snapshot' => $business->tax_rate,
                'product_tax_total_snapshot' => $productTaxTotal->toScale(2, RoundingMode::HalfUp)->__toString(),
                'delivery_fee' => $offer->delivery_fee, 'total' => $offer->total,
                'contact_snapshot' => $offer->contact_snapshot,
                'address_snapshot' => $offer->address_snapshot,
                'delivery_area_code_snapshot' => $offer->delivery_area_code_snapshot,
                'delivery_area_name_snapshot' => $offer->delivery_area_name_snapshot,
                'pickup_location_code_snapshot' => $offer->pickup_location_code_snapshot,
                'pickup_location_name_snapshot' => $offer->pickup_location_name_snapshot,
                'pickup_location_address_snapshot' => $offer->pickup_location_address_snapshot,
                'pickup_location_city_snapshot' => $offer->pickup_location_city_snapshot,
                'location_code_snapshot' => $location->code, 'location_name_snapshot' => $location->name,
                'submitted_at' => $submittedAt, 'reservation_expires_at' => $reservationExpiry,
                'cod_confirmation_deadline_at' => $offer->payment_method === 'cod' ? $policyDeadline : null,
                'bank_proof_deadline_at' => $offer->payment_method === 'bank_transfer' ? $policyDeadline : null,
            ]);
            $orderItemIds = [];
            foreach ($itemsForOrder as $index => $item) {
                $acceptedVariant = ProductVariant::query()->where('business_id', $business->id)->findOrFail($item->product_variant_id);
                $acceptedProduct = Product::query()->where('business_id', $business->id)->findOrFail($item->product_id);
                $inventorySnapshot = [];
                foreach ($allocations as $allocation) {
                    if ($allocation['order_line'] !== $index) {
                        continue;
                    }
                    $component = ProductVariant::query()->where('business_id', $business->id)
                        ->with('product')->findOrFail($allocation['variant_id']);
                    $device = $allocation['device_id'] === null ? null : SerializedDevice::query()
                        ->where('business_id', $business->id)->findOrFail($allocation['device_id']);
                    $inventorySnapshot[] = [
                        'variant_id' => $component->id, 'device_id' => $device?->id,
                        'quantity' => $allocation['quantity'], 'sku' => $component->sku,
                        'name' => $component->product->name.' — '.$component->name,
                        'imei' => $device?->imei, 'condition' => $device?->condition->value,
                        'unit_price' => $allocation['exact_price'],
                    ];
                }
                $orderItemIds[$index] = DB::table('web_order_items')->insertGetId([
                    'business_id' => $business->id, 'web_order_id' => $order->id,
                    'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
                    'line_number' => $index + 1, 'source_offer_line_number' => $item->line_number,
                    'product_name_snapshot' => $item->product_name, 'product_slug_snapshot' => $item->product_slug,
                    'variant_name_snapshot' => $item->variant_name, 'condition_snapshot' => $item->condition_snapshot,
                    'included_device_conditions_snapshot' => $item->included_device_conditions === null ? null : json_encode($item->included_device_conditions, JSON_THROW_ON_ERROR),
                    'quantity' => BigDecimal::of($item->quantity)->toScale(3)->__toString(),
                    'unit_price' => $item->unit_price, 'line_total' => $item->line_total, 'currency' => $offer->currency,
                    'sku_snapshot' => $acceptedVariant->sku, 'barcode_snapshot' => $acceptedVariant->barcode,
                    'tracking_mode_snapshot' => $acceptedVariant->tracking_mode->value,
                    'is_combo_snapshot' => $acceptedProduct->product_type === ProductType::Combo,
                    'tax_total_snapshot' => $this->includedProductTax((string) $item->line_total, (string) $business->tax_rate),
                    'private_inventory_snapshot' => json_encode($inventorySnapshot, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('web_order_status_histories')->insert([
                'business_id' => $business->id, 'web_order_id' => $order->id, 'from_status' => null,
                'to_status' => $status, 'actor_type' => 'guest', 'actor_id' => null,
                'reason_code' => 'accepted_offer', 'created_at' => now(),
            ]);
            WebOrderPayment::query()->create([
                'business_id' => $business->id, 'web_order_id' => $order->id, 'method' => $offer->payment_method,
                'status' => $paymentStatus, 'currency' => $offer->currency, 'amount_due' => $offer->total,
            ]);
            foreach ($allocations as $index => $allocation) {
                try {
                    $this->reservations->reserveComponent($order, $orderItemIds[$allocation['order_line']],
                        $allocation['variant_id'], $allocation['quantity'], 'allocation-'.($index + 1), $allocation['device_id']);
                } catch (ValidationException $exception) {
                    throw new StorefrontCartException('RESERVATION_CONFLICT', 'Availability changed. Request a fresh offer.', 409);
                }
            }
            if ($order->reservations()->where('status', 'active')->count() !== count($allocations)) {
                throw new \LogicException('The order allocation was incomplete.');
            }
            $offer->update(['status' => 'submitted']);

            return $this->result($order, $cartToken, $secret, false);
        }, 3);
    }

    /** @return array{data: array<string, mixed>, credential: string, replayed: bool} */
    private function result(WebOrder $order, string $cartToken, string $secret, bool $replayed): array
    {
        $credential = $this->credential($order->business_id, $order->reference, $cartToken, $secret);
        if (! hash_equals((string) $order->guest_access_token_hash, hash('sha256', $credential))) {
            throw new StorefrontCartException('ORDER_ACCESS_UNAVAILABLE', 'Order access is temporarily unavailable.', 503);
        }
        $items = DB::table('web_order_items')->where('web_order_id', $order->id)->orderBy('line_number')->get();

        return ['replayed' => $replayed, 'credential' => $credential, 'data' => [
            'reference' => $order->reference, 'offer_reference' => $order->offer_reference_snapshot,
            'status' => $order->status, 'payment_status' => $order->payment_status, 'payment_method' => $order->payment_method,
            'fulfillment_mode' => $order->fulfillment_mode,
            'submitted_at' => $order->submitted_at?->toIso8601String(),
            'reservation_expires_at' => $order->reservation_expires_at?->toIso8601String(),
            'currency' => $order->currency, 'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total,
            'delivery_fee' => $order->delivery_fee, 'total' => $order->total,
            'delivery_area' => ['code' => $order->delivery_area_code_snapshot, 'name' => $order->delivery_area_name_snapshot],
            'pickup_location' => $order->fulfillment_mode === 'shop_pickup'
                ? ['code' => $order->pickup_location_code_snapshot, 'name' => $order->pickup_location_name_snapshot,
                    'address' => $order->pickup_location_address_snapshot, 'city' => $order->pickup_location_city_snapshot] : null,
            'items' => $items->map(fn ($item): array => [
                'slug' => $item->product_slug_snapshot, 'name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot, 'condition' => $item->condition_snapshot,
                'included_device_conditions' => $item->included_device_conditions_snapshot === null ? null : json_decode($item->included_device_conditions_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'line_total' => $item->line_total,
            ])->all(),
            'inventory_reserved' => true,
        ]];
    }

    private function credential(int $businessId, string $reference, string $cartToken, string $secret): string
    {
        return hash_hmac('sha256', $businessId.':'.$reference.':'.$cartToken, $secret);
    }

    private function includedProductTax(string $total, string $rate): string
    {
        return BigDecimal::of($total)->multipliedBy($rate)
            ->dividedBy(BigDecimal::of('100')->plus($rate), 2, RoundingMode::HalfUp)->__toString();
    }

    private function validateOfferLine(Business $business, WebOrderOffer $offer, mixed $item, Product $product, ProductVariant $variant, string $cartKey): void
    {
        $publicName = $product->webListing->public_title ?: $product->name;
        if ($item->business_id !== $business->id || $item->web_order_offer_id !== $offer->id ||
            $item->source_cart_item_key !== $cartKey || $item->product_id !== $product->id ||
            $item->product_variant_id !== $variant->id || $item->product_slug !== $product->webListing->slug ||
            $item->product_name !== $publicName || $item->variant_name !== $variant->name ||
            $item->quantity < 1 || $item->line_number < 1 ||
            ($product->product_type === ProductType::Combo || $variant->tracking_mode !== VariantTrackingMode::Serialized)
                && $item->condition_snapshot !== $variant->condition?->value ||
            BigDecimal::of((string) $item->unit_price)->multipliedBy($item->quantity)->compareTo((string) $item->line_total) !== 0) {
            throw new StorefrontCartException('OFFER_STALE', 'A product or price changed. Request a fresh offer.', 409);
        }
        if ($product->product_type !== ProductType::Combo && $variant->tracking_mode !== VariantTrackingMode::Serialized &&
            BigDecimal::of((string) $item->unit_price)->compareTo((string) $variant->selling_price) !== 0) {
            throw new StorefrontCartException('OFFER_STALE', 'A product price changed. Request a fresh offer.', 409);
        }
        if ($product->product_type === ProductType::Combo && BigDecimal::of((string) $item->unit_price)->compareTo((string) $variant->selling_price) !== 0) {
            throw new StorefrontCartException('OFFER_STALE', 'A bundle price changed. Request a fresh offer.', 409);
        }
    }

    /** @return list<array{variant_id: int, quantity: string, device_id: int|null, condition: string|null, fallback_price: string, exact_price: string}> */
    private function validateAllocations(Business $business, mixed $item, Product $product, ProductVariant $variant): array
    {
        $saved = $item->private_allocation;
        if (! is_array($saved) || $saved === []) {
            throw new StorefrontCartException('OFFER_STALE', 'The offer allocation is incomplete.', 409);
        }
        $expected = $product->product_type === ProductType::Combo
            ? $variant->bundleComponents()->with('componentVariant')->get()->mapWithKeys(fn ($component) => [
                $component->component_variant_id => (string) BigDecimal::of($component->quantity)->multipliedBy($item->quantity),
            ])->all()
            : [$variant->id => (string) $item->quantity];
        if ($expected === [] || count($saved) !== count($expected)) {
            throw new StorefrontCartException('OFFER_STALE', 'Bundle components changed. Request a fresh offer.', 409);
        }
        $result = [];
        $seen = [];
        $publicConditions = [];
        foreach ($saved as $entry) {
            if (! is_array($entry) || ! isset($entry['variant_id'], $entry['quantity']) || ! is_numeric($entry['variant_id'])) {
                throw new StorefrontCartException('OFFER_STALE', 'The offer allocation is invalid.', 409);
            }
            $id = (int) $entry['variant_id'];
            if (isset($seen[$id]) || ! isset($expected[$id]) || BigDecimal::of((string) $entry['quantity'])->compareTo($expected[$id]) !== 0) {
                throw new StorefrontCartException('OFFER_STALE', 'Bundle components changed. Request a fresh offer.', 409);
            }
            $seen[$id] = true;
            $component = ProductVariant::query()->where('business_id', $business->id)->with('product')->find($id);
            if ($component === null || $component->status->value !== 'active' || $component->product->status->value !== 'active') {
                throw new StorefrontCartException('OFFER_STALE', 'A component is unavailable. Request a fresh offer.', 409);
            }
            $amount = BigDecimal::of($expected[$id]);
            if ($component->tracking_mode === VariantTrackingMode::Serialized) {
                $ids = $entry['device_ids'] ?? null;
                if (! is_array($ids) || $amount->remainder('1')->compareTo('0') !== 0 || count($ids) !== (int) (string) $amount) {
                    throw new StorefrontCartException('OFFER_STALE', 'The device allocation is incomplete.', 409);
                }
                $savedPrices = $product->product_type === ProductType::Combo ? ($entry['device_prices'] ?? null) : [(string) $item->unit_price];
                if (! is_array($savedPrices) || count($savedPrices) !== count($ids)) {
                    throw new StorefrontCartException('OFFER_STALE', 'The device prices are incomplete. Request a fresh offer.', 409);
                }
                foreach ($ids as $deviceIndex => $deviceId) {
                    if (! is_int($deviceId) || $deviceId < 1) {
                        throw new StorefrontCartException('OFFER_STALE', 'The device allocation is invalid.', 409);
                    }
                    $device = SerializedDevice::query()->where('business_id', $business->id)->find($deviceId);
                    if ($device === null) {
                        throw new StorefrontCartException('DEVICE_UNAVAILABLE', 'The selected device changed. Request a fresh offer.', 409);
                    }
                    $publicConditions[] = ['variant_name' => $component->name, 'condition' => $device->condition->value];
                    $result[] = ['variant_id' => $id, 'quantity' => '1.000', 'device_id' => $deviceId,
                        'condition' => $device->condition->value, 'fallback_price' => (string) $component->selling_price,
                        'exact_price' => (string) $savedPrices[$deviceIndex]];
                }
                if ($product->product_type !== ProductType::Combo && (count($ids) !== 1 || $item->serialized_device_id !== $ids[0] ||
                    $item->condition_snapshot !== $device->condition->value)) {
                    throw new StorefrontCartException('OFFER_STALE', 'The selected device terms changed. Request a fresh offer.', 409);
                }
            } else {
                if (isset($entry['device_ids']) || $item->serialized_device_id !== null) {
                    throw new StorefrontCartException('OFFER_STALE', 'The allocation type changed. Request a fresh offer.', 409);
                }
                $result[] = ['variant_id' => $id, 'quantity' => $amount->toScale(3)->__toString(),
                    'device_id' => null, 'condition' => null, 'fallback_price' => (string) $component->selling_price,
                    'exact_price' => (string) $item->unit_price];
            }
        }
        if ($product->product_type === ProductType::Combo && $publicConditions !== ($item->included_device_conditions ?? [])) {
            throw new StorefrontCartException('OFFER_STALE', 'Included device conditions changed. Request a fresh offer.', 409);
        }

        return $result;
    }
}
