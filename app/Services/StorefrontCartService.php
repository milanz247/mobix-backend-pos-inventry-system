<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WebCart;
use App\Models\WebCartItem;
use App\Models\WebQuote;
use App\ProductCondition;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontCartService
{
    public const MAX_QUANTITY = 10;

    public const CART_DAYS = 30;

    public const QUOTE_MINUTES = 15;

    public function __construct(
        private readonly StorefrontCatalogService $catalog,
        private readonly StockAvailabilityService $stock,
        private readonly StorefrontCartDemandService $demand,
    ) {}

    public function find(Business $business, ?string $token): ?WebCart
    {
        if ($token === null || $token === '') {
            return null;
        }
        $cart = $this->findForOrderReplay($business, $token);
        if ($cart->status !== 'active' || $cart->expires_at->isPast()) {
            if ($cart->status === 'active') {
                $cart->update(['status' => 'expired']);
            }
            throw new StorefrontCartException('CART_EXPIRED', 'This cart has expired. Start a new cart.', 410);
        }

        return $cart;
    }

    /** Resolve ownership without enforcing lifetime, solely so committed idempotent submissions can be replayed. */
    public function findForOrderReplay(Business $business, string $token): WebCart
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new StorefrontCartException('CART_NOT_FOUND', 'The cart could not be found.', 404);
        }
        $cart = WebCart::query()->where('business_id', $business->id)->where('token_hash', hash('sha256', $token))->first();
        if ($cart === null) {
            throw new StorefrontCartException('CART_NOT_FOUND', 'The cart could not be found.', 404);
        }

        return $cart;
    }

    /** @return array{cart: WebCart, token: string|null} */
    public function add(Business $business, ?string $token, int $productId, int $variantId, int $quantity): array
    {
        $cart = $this->find($business, $token);
        $newToken = null;
        DB::transaction(function () use ($business, &$cart, &$newToken, $productId, $variantId, $quantity): void {
            if ($cart === null) {
                $newToken = bin2hex(random_bytes(32));
                $cart = WebCart::query()->create([
                    'business_id' => $business->id, 'token_hash' => hash('sha256', $newToken),
                    'status' => 'active', 'expires_at' => now()->addDays(self::CART_DAYS), 'last_activity_at' => now(),
                ]);
            }
            $cart = WebCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($cart);
            [$product, $variant] = $this->eligibleVariant($business, $productId, $variantId);
            $existing = $cart->items()->where('product_variant_id', $variantId)->first();
            $nextQuantity = $quantity + ($existing === null ? 0 : $existing->quantity);
            if ($nextQuantity > self::MAX_QUANTITY) {
                throw new StorefrontCartException('INVALID_QUANTITY', 'The maximum quantity for one option is 10.', 422);
            }
            if ($cart->items()->count() >= 30 && $existing === null) {
                throw new StorefrontCartException('CART_FULL', 'This cart has reached its item limit.', 422);
            }
            $this->assertQuantity($variant, $nextQuantity);
            $this->assertAvailable($business, $variant, $nextQuantity);
            if ($existing) {
                $existing->update(['quantity' => $nextQuantity]);
            } else {
                $cart->items()->create([
                    'public_key' => (string) Str::ulid(), 'business_id' => $business->id,
                    'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => $nextQuantity,
                ]);
            }
            $this->touch($cart);
        });

        return ['cart' => $cart->fresh(), 'token' => $newToken];
    }

    public function update(Business $business, WebCart $cart, string $key, int $quantity): void
    {
        DB::transaction(function () use ($business, $cart, $key, $quantity): void {
            $locked = WebCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($locked);
            $item = $this->ownedItem($locked, $key);
            [, $variant] = $this->eligibleVariant($business, $item->product_id, $item->product_variant_id);
            $this->assertQuantity($variant, $quantity);
            $this->assertAvailable($business, $variant, $quantity);
            $item->update(['quantity' => $quantity]);
            $this->touch($locked);
        });
    }

    public function remove(WebCart $cart, string $key): void
    {
        DB::transaction(function () use ($cart, $key): void {
            $locked = WebCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($locked);
            $this->ownedItem($locked, $key)->delete();
            $this->touch($locked);
        });
    }

    public function clear(WebCart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $locked = WebCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($locked);
            $locked->items()->delete();
            $this->touch($locked);
        });
    }

    /** @return array<string, mixed> */
    public function view(Business $business, ?WebCart $cart): array
    {
        $lines = [];
        $subtotal = BigDecimal::zero();
        $canQuote = true;
        foreach ($cart?->items()->orderBy('id')->get() ?? [] as $item) {
            $product = $this->catalog->products($business)->whereKey($item->product_id)->first();
            $variant = $product?->variants->firstWhere('id', $item->product_variant_id);
            if ($product === null || $variant === null) {
                $canQuote = false;
                $lines[] = ['key' => $item->public_key, 'name' => 'Product no longer available', 'slug' => null,
                    'variant' => null, 'quantity' => $item->quantity, 'unit_price' => null, 'line_total' => null,
                    'image_url' => null, 'availability' => 'out_of_stock', 'requires_device_confirmation' => false];

                continue;
            }
            $price = $this->catalog->startingPrice($variant);
            $locations = $this->eligibleLocations($business, $variant, $item->quantity);
            $availability = $locations === [] ? 'out_of_stock' : $this->catalog->availability($variant, $business);
            if ($price === null || $availability !== 'in_stock') {
                $canQuote = false;
            }
            $lineTotal = $price === null ? null : $this->money(BigDecimal::of($price)->multipliedBy($item->quantity));
            if ($lineTotal !== null) {
                $subtotal = $subtotal->plus($lineTotal);
            }
            $lines[] = ['key' => $item->public_key, 'name' => $product->webListing->public_title ?: $product->name,
                'slug' => $product->webListing->slug, 'variant' => ['id' => $variant->id, 'name' => $variant->name, 'condition' => $variant->condition?->value],
                'quantity' => $item->quantity, 'unit_price' => $price, 'line_total' => $lineTotal,
                'image_url' => $this->catalog->imageUrl($product->image_path), 'availability' => $availability,
                'requires_device_confirmation' => $this->isUsedSerialized($variant)];
        }

        return ['items' => $lines, 'item_count' => array_sum(array_column($lines, 'quantity')),
            'currency' => $business->currency, 'indicative_subtotal' => $canQuote ? $this->money($subtotal) : null,
            'can_quote' => $canQuote && $lines !== [], 'availability_is_advisory' => true,
            'cart_expires_at' => $cart?->expires_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function quote(Business $business, WebCart $cart): array
    {
        return DB::transaction(function () use ($business, $cart): array {
            $locked = WebCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->assertLive($locked);
            $items = $locked->items()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new StorefrontCartException('EMPTY_CART', 'Add a product before requesting a quote.', 422);
            }
            $lines = [];
            $subtotal = BigDecimal::zero();
            $commonLocations = null;
            $demandLines = [];
            foreach ($items as $item) {
                [$product, $variant] = $this->eligibleVariant($business, $item->product_id, $item->product_variant_id);
                $this->assertQuantity($variant, $item->quantity);
                $demandLines[] = ['product' => $product, 'variant' => $variant, 'quantity' => $item->quantity];
                $locations = $this->eligibleLocations($business, $variant, $item->quantity);
                if ($locations === []) {
                    throw new StorefrontCartException('INSUFFICIENT_AVAILABILITY', 'One or more items are no longer available in the requested quantity.');
                }
                $commonLocations = $commonLocations === null ? $locations : array_values(array_intersect($commonLocations, $locations));
                $price = $this->catalog->startingPrice($variant);
                if ($price === null) {
                    throw new StorefrontCartException('PRICE_UNAVAILABLE', 'A current price is unavailable for one or more items.');
                }
                $lineTotal = $this->money(BigDecimal::of($price)->multipliedBy($item->quantity));
                $subtotal = $subtotal->plus($lineTotal);
                $lines[] = ['product_id' => $product->id, 'product_variant_id' => $variant->id,
                    'product_slug' => $product->webListing->slug, 'product_name' => $product->webListing->public_title ?: $product->name,
                    'variant_name' => $variant->name, 'quantity' => $item->quantity,
                    'unit_price' => $price, 'line_total' => $lineTotal, 'availability' => 'in_stock',
                    'requires_device_confirmation' => $this->isUsedSerialized($variant)];
            }
            if ($commonLocations === []) {
                throw new StorefrontCartException('NO_SINGLE_LOCATION', 'No single location can currently fulfill all cart items.');
            }
            $canFulfill = false;
            foreach ($commonLocations as $locationId) {
                $location = Location::query()->where('business_id', $business->id)->where('status', 'active')->find($locationId);
                if ($location !== null && $this->demand->canFulfill($business, $location, $demandLines)) {
                    $canFulfill = true;
                    break;
                }
            }
            if (! $canFulfill) {
                throw new StorefrontCartException('NO_SINGLE_LOCATION', 'No single location can currently fulfill the complete cart.');
            }
            $now = now();
            $quote = WebQuote::query()->create(['business_id' => $business->id, 'web_cart_id' => $locked->id,
                'reference' => (string) Str::ulid(), 'status' => 'indicative', 'currency' => $business->currency,
                'subtotal' => $this->money($subtotal), 'total' => $this->money($subtotal),
                'expires_at' => $now->copy()->addMinutes(self::QUOTE_MINUTES)]);
            $quote->items()->createMany(array_map(fn (array $line): array => ['business_id' => $business->id] + $line, $lines));
            $this->touch($locked);

            return ['reference' => $quote->reference, 'status' => 'indicative',
                'created_at' => $quote->created_at->toIso8601String(), 'expires_at' => $quote->expires_at->toIso8601String(),
                'currency' => $quote->currency, 'subtotal' => $quote->subtotal, 'total' => $quote->total,
                'discount_total' => '0.00', 'delivery_included' => false, 'total_is_final' => false,
                'availability_is_advisory' => true, 'inventory_reserved' => false,
                'items' => array_map(fn (array $line): array => [
                    'slug' => $line['product_slug'], 'name' => $line['product_name'], 'variant_name' => $line['variant_name'],
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'],
                    'availability' => $line['availability'], 'requires_device_confirmation' => $line['requires_device_confirmation'],
                ], $lines)];
        });
    }

    /** @return array{Product, ProductVariant} */
    private function eligibleVariant(Business $business, int $productId, int $variantId): array
    {
        $product = $this->catalog->products($business)->whereKey($productId)->first();
        $variant = $product?->variants->firstWhere('id', $variantId);
        if ($product === null || $variant === null || $variant->business_id !== $business->id) {
            throw new StorefrontCartException('PRODUCT_UNAVAILABLE', 'The selected product option is unavailable.', 422);
        }

        return [$product, $variant];
    }

    private function assertQuantity(ProductVariant $variant, int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY || ($this->isUsedSerialized($variant) && $quantity !== 1)) {
            throw new StorefrontCartException('INVALID_QUANTITY', 'The selected quantity is not supported for this option.', 422);
        }
    }

    private function assertAvailable(Business $business, ProductVariant $variant, int $quantity): void
    {
        if ($this->eligibleLocations($business, $variant, $quantity) === []) {
            throw new StorefrontCartException('INSUFFICIENT_AVAILABILITY', 'This option is not currently available in the requested quantity.');
        }
        if ($this->catalog->startingPrice($variant) === null) {
            throw new StorefrontCartException('PRICE_UNAVAILABLE', 'A current price is unavailable for this option.');
        }
    }

    /** @return list<int> */
    private function eligibleLocations(Business $business, ProductVariant $variant, int $quantity): array
    {
        $eligible = [];
        foreach (Location::query()->where('business_id', $business->id)->where('status', 'active')->pluck('id') as $locationId) {
            $location = new Location;
            $location->id = $locationId;
            $row = $this->stock->query($business, $location)->with('product')->where('product_variants.id', $variant->id)->first();
            if ($row === null) {
                continue;
            }
            $status = $this->stock->describe($row);
            if ($status['can_sell'] && BigDecimal::of($status['available_quantity'])->isGreaterThanOrEqualTo((string) $quantity)) {
                $eligible[] = (int) $locationId;
            }
        }

        return $eligible;
    }

    private function isUsedSerialized(ProductVariant $variant): bool
    {
        return $variant->condition === ProductCondition::Used && $variant->tracking_mode === VariantTrackingMode::Serialized;
    }

    private function ownedItem(WebCart $cart, string $key): WebCartItem
    {
        $item = $cart->items()->where('public_key', $key)->first();
        if ($item === null) {
            throw new StorefrontCartException('ITEM_NOT_FOUND', 'The cart item could not be found.', 404);
        }

        return $item;
    }

    private function assertLive(WebCart $cart): void
    {
        if ($cart->status !== 'active' || $cart->expires_at->isPast()) {
            throw new StorefrontCartException('CART_EXPIRED', 'This cart has expired. Start a new cart.', 410);
        }
    }

    private function touch(WebCart $cart): void
    {
        $cart->update(['last_activity_at' => now(), 'expires_at' => now()->addDays(self::CART_DAYS)]);
    }

    private function money(BigDecimal $amount): string
    {
        return $amount->toScale(2)->__toString();
    }
}
