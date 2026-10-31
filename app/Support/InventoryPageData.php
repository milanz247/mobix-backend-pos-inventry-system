<?php

namespace App\Support;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\User;
use App\ProductType;
use App\Services\LocationAccessService;
use App\VariantTrackingMode;

class InventoryPageData
{
    public function __construct(private readonly LocationAccessService $locationAccess) {}

    /** @return list<array{id: int, name: string, code: string}> */
    public function locations(User $user): array
    {
        return array_values($this->locationAccess->accessibleQuery($user)
            ->where('status', 'active')->orderByDesc('is_main')->orderBy('name')
            ->get(['id', 'name', 'code'])->map(fn ($location): array => [
                'id' => $location->id, 'name' => $location->name, 'code' => $location->code,
            ])->all());
    }

    /** @return list<array{id: int, label: string, sku: string, tracking_mode: string}> */
    public function variants(Business $business, ?VariantTrackingMode $trackingMode = null): array
    {
        return array_values($business->productVariants()->with('product:id,name,product_type,status')
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query
                ->where('status', CatalogStatus::Active->value)
                ->whereNotIn('product_type', [ProductType::Combo->value, ProductType::Service->value]))
            ->when($trackingMode !== null, fn ($query) => $query->where('tracking_mode', $trackingMode->value))
            ->orderBy('sku')->get()->map(fn ($variant): array => [
                'id' => $variant->id,
                'label' => $variant->product->name.' — '.$variant->name,
                'sku' => $variant->sku,
                'tracking_mode' => $variant->tracking_mode->value,
            ])->all());
    }
}
