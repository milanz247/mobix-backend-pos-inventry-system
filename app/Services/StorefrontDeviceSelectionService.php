<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\StorefrontDeviceSelection;
use App\ProductCondition;
use App\VariantTrackingMode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;

class StorefrontDeviceSelectionService
{
    private const TOKEN_TTL_MINUTES = 10;

    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    /** @return array{variant: ProductVariant, options: list<array<string, mixed>>} */
    public function options(Business $business, string $slug, int $variantId): array
    {
        $product = $this->publishedProduct($business, $slug);
        $variant = $product->variants->firstWhere('id', $variantId);
        if ($variant === null || $variant->condition !== ProductCondition::Used || $variant->tracking_mode !== VariantTrackingMode::Serialized) {
            throw $this->unavailable();
        }

        $options = array_values($this->catalog->eligibleUsedDevices($business, $variant)
            ->orderBy('serialized_devices.id')
            ->get(['serialized_devices.id', 'serialized_devices.business_id', 'serialized_devices.product_variant_id', 'serialized_devices.location_id', 'serialized_devices.condition', 'serialized_devices.status', 'serialized_devices.unit_selling_price'])
            ->map(fn (SerializedDevice $device): array => [
                'option_key' => Crypt::encryptString(json_encode([
                    'business' => $business->id,
                    'product' => $product->id,
                    'variant' => $variant->id,
                    'device' => $device->id,
                    'price' => $this->catalog->usedDevicePrice($device, $variant),
                    'expires' => now()->addMinutes(self::TOKEN_TTL_MINUTES)->timestamp,
                ], JSON_THROW_ON_ERROR)),
                'variant_name' => $variant->name,
                'model_number' => $variant->model_number,
                'storage_capacity' => $variant->storage_capacity,
                'color' => $variant->color,
                'condition' => ProductCondition::Used->label(),
                'price' => $this->catalog->usedDevicePrice($device, $variant),
                'currency' => $business->currency,
                'availability' => 'in_stock',
            ])->all());

        return ['variant' => $variant, 'options' => $options];
    }

    /** @return array{token: string, expires_at: string} */
    public function createSelection(Business $business, string $optionKey): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($optionKey), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $this->unavailable();
        }

        if (! is_array($payload)
            || ($payload['business'] ?? null) !== $business->id
            || ! is_int($payload['product'] ?? null)
            || ! is_int($payload['variant'] ?? null)
            || ! is_int($payload['device'] ?? null)
            || ! is_string($payload['price'] ?? null)
            || ! is_int($payload['expires'] ?? null)
            || $payload['expires'] <= now()->timestamp) {
            throw $this->unavailable();
        }

        $product = $this->publishedProduct($business, '', $payload['product']);
        $variant = $product->variants->firstWhere('id', $payload['variant']);
        if ($variant === null || $variant->condition !== ProductCondition::Used || $variant->tracking_mode !== VariantTrackingMode::Serialized) {
            throw $this->unavailable();
        }

        return DB::transaction(function () use ($business, $product, $variant, $payload): array {
            $device = $this->catalog->eligibleUsedDevices($business, $variant)
                ->whereKey($payload['device'])->lockForUpdate()->first();
            if ($device === null || $this->catalog->usedDevicePrice($device, $variant) !== $payload['price']) {
                throw $this->unavailable();
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
            StorefrontDeviceSelection::query()->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'serialized_device_id' => $device->id,
                'token_hash' => hash('sha256', $token),
                'selected_price' => $payload['price'],
                'expires_at' => $expiresAt,
            ]);

            return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String()];
        });
    }

    private function publishedProduct(Business $business, string $slug, ?int $productId = null): Product
    {
        $query = $this->catalog->products($business);
        if ($productId !== null) {
            $query->whereKey($productId);
        } else {
            $query->whereHas('webListing', fn ($listing) => $listing->where('slug', $slug));
        }

        return $query->firstOrFail();
    }

    private function unavailable(): StorefrontCartException
    {
        return new StorefrontCartException('DEVICE_UNAVAILABLE', 'This device option is no longer available. Refresh the options and try again.', 422);
    }
}
