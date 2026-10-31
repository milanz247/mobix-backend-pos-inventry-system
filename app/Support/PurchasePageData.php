<?php

namespace App\Support;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\User;
use App\ProductType;
use App\Services\LocationAccessService;
use App\SupplierStatus;

class PurchasePageData
{
    public function __construct(private readonly LocationAccessService $locationAccess) {}

    /** @return array{currency: string, suppliers: list<array{id: int, name: string}>, locations: list<array{id: int, name: string, code: string}>, variants: list<array{id: int, label: string, sku: string, tracking_mode: string, cost_price: string, selling_price: string}>} */
    public function options(User $user): array
    {
        $business = $this->locationAccess->business();

        return [
            'currency' => $business->currency,
            'suppliers' => array_values($business->suppliers()->where('status', SupplierStatus::Active->value)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])->all()),
            'locations' => array_values($this->locationAccess->accessibleQuery($user)
                ->where('status', 'active')->orderByDesc('is_main')->orderBy('name')
                ->get(['id', 'name', 'code'])->map(fn ($location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'code' => $location->code,
                ])->all()),
            'variants' => $this->variants($business),
        ];
    }

    /** @return list<array{id: int, label: string, sku: string, tracking_mode: string, cost_price: string, selling_price: string}> */
    private function variants(Business $business): array
    {
        return array_values($business->productVariants()->with('product:id,name,product_type,status')
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query
                ->where('status', CatalogStatus::Active->value)
                ->whereNotIn('product_type', [ProductType::Combo->value, ProductType::Service->value]))
            ->orderBy('sku')->limit(25)->get()->map(fn ($variant): array => [
                'id' => $variant->id,
                'label' => $variant->product->name.' — '.$variant->name,
                'sku' => $variant->sku,
                'tracking_mode' => $variant->tracking_mode->value,
                'cost_price' => $variant->cost_price,
                'selling_price' => $variant->selling_price,
            ])->all());
    }

    /** @return array<string, mixed> */
    public function purchase(Purchase $purchase, User $user): array
    {
        $purchase->loadMissing(['lines.variant.product', 'lines.devices.serializedDevice.location']);
        $locationIds = $this->locationAccess->accessibleQuery($user)->pluck('id');

        return [
            'id' => $purchase->id,
            'invoice_number' => $purchase->invoice_number,
            'invoice_date' => $purchase->invoice_date->toDateString(),
            'notes' => $purchase->notes,
            'status' => $purchase->status->value,
            'payment_status' => $purchase->payment_status->value,
            'subtotal' => $purchase->subtotal,
            'discount_total' => $purchase->discount_total,
            'total' => $purchase->total,
            'paid_total' => $purchase->paid_total,
            'supplier' => ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name],
            'location' => ['id' => $purchase->location->id, 'name' => $purchase->location->name, 'code' => $purchase->location->code],
            'lines' => $purchase->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_variant_id' => $line->product_variant_id,
                'label' => $line->variant->product->name.' — '.$line->variant->name,
                'sku' => $line->variant->sku,
                'tracking_mode' => $line->tracking_mode->value,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost,
                'subtotal' => $line->subtotal,
                'discount_amount' => $line->discount_amount,
                'total' => $line->total,
                'devices' => $line->devices->map(fn ($device): array => [
                    'id' => $device->id,
                    'imei' => $device->imei,
                    'imei2' => $device->imei2,
                    'serial_number' => $device->serial_number,
                    'condition' => $device->condition->value,
                    'unit_cost' => $device->unit_cost,
                    'unit_selling_price' => $device->unit_selling_price,
                    'serialized_device_id' => $locationIds->contains($device->serializedDevice?->location_id) ? $device->serialized_device_id : null,
                    'status' => $locationIds->contains($device->serializedDevice?->location_id) ? $device->serializedDevice?->status->value : null,
                    'location_id' => $locationIds->contains($device->serializedDevice?->location_id) ? $device->serializedDevice?->location_id : null,
                    'location_name' => $locationIds->contains($device->serializedDevice?->location_id) ? $device->serializedDevice?->location?->name : null,
                ])->values()->all(),
            ])->values()->all(),
            'payments' => $purchase->payments()->with('recorder:id,name')->orderBy('payment_date')->get()->map(fn ($payment): array => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'recorded_by' => $payment->recorder->name,
            ])->values()->all(),
            'returns' => $purchase->returns()->whereIn('location_id', $locationIds)->with(['location:id,name,code', 'lines.variant.product'])->latest('return_date')->get()->map(fn ($return): array => [
                'id' => $return->id,
                'return_number' => $return->return_number,
                'return_date' => $return->return_date->toDateString(),
                'location' => $return->location->name,
                'total' => $return->total,
                'status' => $return->status,
            ])->values()->all(),
        ];
    }
}
