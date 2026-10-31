<?php

namespace App\Services;

use App\CatalogStatus;
use App\InventoryOperationType;
use App\Models\Business;
use App\Models\DamagedStockEntry;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\User;
use App\ProductType;
use App\SerializedDeviceEventType;
use App\SerializedDeviceStatus;
use App\StockMovementType;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly LocationAccessService $locationAccess) {}

    /** @param array<string, mixed> $data */
    public function receive(User $user, array $data, bool $opening = false): InventoryOperation
    {
        $type = $opening ? InventoryOperationType::OpeningStock : InventoryOperationType::Receiving;

        return DB::transaction(function () use ($user, $data, $type): InventoryOperation {
            $business = $this->locationAccess->business();
            $operation = $this->existingOrNewOperation($business, $user, $data, $type);

            if ($operation->exists && $operation->lines()->exists()) {
                $this->authorizedLocation($user, (int) $data['location_id']);

                return $operation;
            }

            $location = $this->authorizedLocation($user, (int) $data['location_id']);
            $variant = $this->activeVariant($business, (int) $data['product_variant_id']);
            $operation->update(['destination_location_id' => $location->id]);

            if ($data['mode'] === 'quantity') {
                $this->assertQuantityVariant($variant);
                $quantity = $this->normalizeQuantity((string) $data['quantity']);
                $operation->lines()->create([
                    'business_id' => $business->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'unit_cost' => $data['unit_cost'] ?? null,
                ]);
                $this->changeQuantity(
                    $operation,
                    $location,
                    $variant,
                    $quantity,
                    $type === InventoryOperationType::OpeningStock ? StockMovementType::OpeningBalance : StockMovementType::Receiving,
                );
            } else {
                $this->assertSerializedVariant($variant);
                $eventType = $type === InventoryOperationType::OpeningStock
                    ? SerializedDeviceEventType::OpeningBalance
                    : SerializedDeviceEventType::Received;

                foreach ($data['devices'] as $deviceData) {
                    $this->createDevice($operation, $location, $variant, $user, $deviceData, $eventType);
                }
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    public function receivePurchase(User $user, Purchase $purchase): InventoryOperation
    {
        return DB::transaction(function () use ($user, $purchase): InventoryOperation {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->with(['lines.devices'])->lockForUpdate()->findOrFail($purchase->id);
            $location = $this->authorizedLocation($user, $purchase->location_id);
            $lines = $purchase->lines->map(fn ($line): array => [
                'product_variant_id' => $line->product_variant_id,
                'tracking_mode' => $line->tracking_mode->value,
                'quantity' => (string) $line->quantity,
                'unit_cost' => (string) $line->unit_cost,
                'discount_amount' => (string) $line->discount_amount,
                'devices' => $line->devices->map(fn ($device): array => [
                    'id' => $device->id,
                    'imei' => $device->imei,
                    'imei2' => $device->imei2,
                    'serial_number' => $device->serial_number,
                    'condition' => $device->condition->value,
                    'unit_cost' => (string) $device->unit_cost,
                    'unit_selling_price' => $device->unit_selling_price,
                ])->all(),
            ])->all();
            $data = [
                'operation_key' => $purchase->operation_key,
                'location_id' => $location->id,
                'reference' => $purchase->invoice_number,
                'reason' => 'supplier_purchase',
                'notes' => $purchase->notes,
                'lines' => $lines,
            ];
            $operation = $this->existingOrNewOperation($business, $user, $data, InventoryOperationType::Receiving);

            if ($operation->lines()->exists()) {
                return $operation;
            }

            $operation->update(['destination_location_id' => $location->id]);

            foreach ($purchase->lines as $line) {
                $variant = $this->activeVariant($business, $line->product_variant_id);
                if ($variant->tracking_mode !== $line->tracking_mode) {
                    throw ValidationException::withMessages(['lines' => __('The product tracking mode changed after this draft was created.')]);
                }

                if ($variant->tracking_mode === VariantTrackingMode::QuantityBased) {
                    $this->assertQuantityVariant($variant, 'lines');
                    $quantity = $this->normalizeQuantity((string) $line->quantity);
                    $operation->lines()->create([
                        'business_id' => $business->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $quantity,
                        'unit_cost' => $line->unit_cost,
                    ]);
                    $this->changeQuantity($operation, $location, $variant, $quantity, StockMovementType::Receiving);

                    continue;
                }

                $this->assertSerializedVariant($variant);
                foreach ($line->devices as $deviceData) {
                    $device = $this->createDevice($operation, $location, $variant, $user, [
                        'imei' => $deviceData->imei,
                        'imei2' => $deviceData->imei2,
                        'serial_number' => $deviceData->serial_number,
                        'condition' => $deviceData->condition->value,
                        'unit_cost' => (string) $deviceData->unit_cost,
                        'unit_selling_price' => $deviceData->unit_selling_price,
                    ], SerializedDeviceEventType::Received);
                    $deviceData->update(['serialized_device_id' => $device->id]);
                }
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    /** @param list<array<string, mixed>> $items */
    public function returnPurchase(User $user, Purchase $purchase, PurchaseReturn $purchaseReturn, array $items): InventoryOperation
    {
        return DB::transaction(function () use ($user, $purchase, $purchaseReturn, $items): InventoryOperation {
            $business = $this->locationAccess->business();
            $location = $this->authorizedLocation($user, $purchaseReturn->location_id);
            $data = [
                'operation_key' => $purchaseReturn->operation_key,
                'location_id' => $location->id,
                'reference' => $purchaseReturn->return_number,
                'reason' => 'supplier_purchase_return',
                'notes' => $purchaseReturn->notes,
                'purchase_id' => $purchase->id,
                'items' => $items,
            ];
            $operation = $this->existingOrNewOperation($business, $user, $data, InventoryOperationType::PurchaseReturn);

            if ($operation->lines()->exists() || $operation->deviceEvents()->exists()) {
                return $operation;
            }

            $operation->update(['source_location_id' => $location->id]);

            foreach ($items as $item) {
                $variant = $business->productVariants()->with('product')->findOrFail((int) $item['product_variant_id']);
                if ($variant->product->product_type === ProductType::Combo) {
                    throw ValidationException::withMessages(['lines' => __('Virtual combo products cannot be returned as stock.')]);
                }

                if ($item['serialized_device_id'] === null) {
                    $this->assertQuantityVariant($variant, 'lines');
                    $quantity = $this->normalizeQuantity((string) $item['quantity']);
                    $operation->lines()->create([
                        'business_id' => $business->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => '-'.$quantity,
                        'unit_cost' => $item['unit_cost'],
                    ]);
                    $this->changeQuantity($operation, $location, $variant, '-'.$quantity, StockMovementType::PurchaseReturn);

                    continue;
                }

                $this->assertSerializedVariant($variant);
                $device = SerializedDevice::query()
                    ->where('business_id', $business->id)
                    ->where('product_variant_id', $variant->id)
                    ->where('received_operation_id', $purchase->inventory_operation_id)
                    ->where('location_id', $location->id)
                    ->where('status', SerializedDeviceStatus::InStock->value)
                    ->lockForUpdate()
                    ->find((int) $item['serialized_device_id']);

                if ($device === null) {
                    throw ValidationException::withMessages(['lines' => __('The selected device is no longer in stock at this location or was not received on this purchase.')]);
                }

                $operation->lines()->create([
                    'business_id' => $business->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => '-1.000',
                    'unit_cost' => $device->unit_cost,
                    'condition' => $device->condition,
                ]);
                $device->events()->create([
                    'business_id' => $business->id,
                    'inventory_operation_id' => $operation->id,
                    'event_group_id' => $operation->operation_key,
                    'event_type' => SerializedDeviceEventType::ReturnedToSupplier,
                    'from_location_id' => $location->id,
                    'status_before' => $device->status,
                    'status_after' => SerializedDeviceStatus::ReturnedToSupplier,
                    'reason' => $operation->reason,
                    'notes' => $operation->notes,
                    'performed_by' => $user->id,
                ]);
                $device->update(['status' => SerializedDeviceStatus::ReturnedToSupplier]);
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    /** @param list<int>|null $lineIds */
    public function postSale(User $user, Sale $sale, ?array $lineIds = null, ?string $operationKey = null): InventoryOperation
    {
        return DB::transaction(function () use ($user, $sale, $lineIds, $operationKey): InventoryOperation {
            $business = $this->locationAccess->business();
            $sale = $business->sales()->with(['lines.variant.product', 'lines.serializedDevice', 'lines.components.variant.product'])
                ->lockForUpdate()->findOrFail($sale->id);
            $location = $this->authorizedLocation($user, $sale->location_id);

            if ($lineIds === null && $sale->inventory_operation_id !== null) {
                return InventoryOperation::query()->findOrFail($sale->inventory_operation_id);
            }

            $operation = $this->existingOrNewOperation($business, $user, [
                'operation_key' => $operationKey ?? $sale->operation_key,
                'location_id' => $location->id,
                'reference' => $sale->sale_number,
                'reason' => 'pos_sale',
                'notes' => $sale->notes,
            ], InventoryOperationType::Sale);
            $operation->update(['source_location_id' => $location->id]);

            foreach ($sale->lines as $line) {
                if ($lineIds !== null && ! in_array($line->id, $lineIds, true)) {
                    continue;
                }
                if ($line->is_combo) {
                    $this->consumeBundleComponents($user, $sale, $operation, $location, $line);

                    continue;
                }

                if ($line->serialized_device_id !== null) {
                    $device = SerializedDevice::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($line->serialized_device_id);
                    $this->markDeviceSold($user, $operation, $sale, $location, $device, $line->unit_price);

                    continue;
                }

                $variant = $this->activeVariant($business, $line->product_variant_id);
                $this->assertQuantityVariant($variant, 'lines');
                $quantity = $this->normalizeQuantity((string) $line->quantity);
                $operation->lines()->create([
                    'business_id' => $business->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => '-'.$quantity,
                    'unit_cost' => $line->cost_snapshot,
                ]);
                $this->changeQuantity($operation, $location, $variant, '-'.$quantity, StockMovementType::Sale, $sale);
            }

            if ($lineIds === null) {
                $sale->update(['inventory_operation_id' => $operation->id]);
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    public function returnSale(User $user, SaleReturn $saleReturn): InventoryOperation
    {
        return DB::transaction(function () use ($user, $saleReturn): InventoryOperation {
            $business = $this->locationAccess->business();
            $saleReturn = SaleReturn::query()->where('business_id', $business->id)
                ->with('sale')->lockForUpdate()->findOrFail($saleReturn->id);
            $location = $this->authorizedLocation($user, $saleReturn->location_id);
            $operation = $this->existingOrNewOperation($business, $user, [
                'operation_key' => $saleReturn->operation_key,
                'location_id' => $location->id,
                'reference' => $saleReturn->return_number,
                'reason' => 'sale_return',
                'notes' => $saleReturn->notes,
            ], InventoryOperationType::SaleReturn);

            if ($operation->lines()->exists() || $operation->deviceEvents()->exists() || DamagedStockEntry::query()->where('inventory_operation_id', $operation->id)->exists()) {
                return $operation;
            }

            $operation->update(['destination_location_id' => $location->id]);
            $returnLines = $saleReturn->lines()->with(['saleLine.variant.product', 'component.variant.product', 'serializedDevice'])->get();

            foreach ($returnLines as $returnLine) {
                if (! $returnLine->restock) {
                    if ($returnLine->serialized_device_id !== null) {
                        $device = SerializedDevice::query()->where('business_id', $business->id)
                            ->lockForUpdate()->findOrFail($returnLine->serialized_device_id);
                        if ($device->status !== SerializedDeviceStatus::Sold || $device->location_id !== $location->id) {
                            throw ValidationException::withMessages(['lines' => __('The device is no longer sold from this location.')]);
                        }
                        $this->markDeviceReturned($user, $operation, $saleReturn, $location, $device, SerializedDeviceStatus::Damaged);
                    }

                    $variant = $returnLine->is_component ? $returnLine->component->variant : $returnLine->saleLine->variant;
                    if ($returnLine->serialized_device_id === null && $variant->tracking_mode === VariantTrackingMode::QuantityBased && $variant->product->product_type !== ProductType::Combo) {
                        DamagedStockEntry::query()->create([
                            'business_id' => $business->id, 'location_id' => $location->id,
                            'product_variant_id' => $variant->id, 'inventory_operation_id' => $operation->id,
                            'sale_return_line_id' => $returnLine->id, 'quantity' => $returnLine->quantity,
                            'remaining_quantity' => $returnLine->quantity, 'unit_cost' => $returnLine->unit_cost_snapshot, 'reason' => $saleReturn->reason,
                            'notes' => $saleReturn->notes, 'recorded_by' => $user->id,
                        ]);
                    }

                    continue;
                }
                if ($returnLine->serialized_device_id !== null) {
                    $device = SerializedDevice::query()->where('business_id', $business->id)
                        ->lockForUpdate()->findOrFail($returnLine->serialized_device_id);
                    if ($device->status !== SerializedDeviceStatus::Sold || $device->location_id !== $location->id) {
                        throw ValidationException::withMessages(['lines' => __('The device is no longer sold from this location.')]);
                    }
                    $this->markDeviceReturned($user, $operation, $saleReturn, $location, $device);

                    continue;
                }

                $variant = $returnLine->is_component
                    ? $returnLine->component->variant
                    : $returnLine->saleLine->variant;
                if ($variant->tracking_mode !== VariantTrackingMode::QuantityBased || $variant->product->product_type === ProductType::Combo) {
                    continue;
                }
                $quantity = $this->normalizeQuantity((string) $returnLine->quantity);
                $operation->lines()->create([
                    'business_id' => $business->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'unit_cost' => $returnLine->unit_cost_snapshot,
                ]);
                $this->changeQuantity($operation, $location, $variant, $quantity, StockMovementType::SaleReturn, $saleReturn);
            }

            $saleReturn->update(['inventory_operation_id' => $operation->id]);

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    private function consumeBundleComponents(User $user, Sale $sale, InventoryOperation $operation, Location $location, SaleLine $line): void
    {
        $bundle = $line->variant()->with('product', 'bundleComponents.componentVariant.product')->firstOrFail();
        $components = $this->resolveBundleAvailability($user, $location, $bundle, (string) $line->quantity);
        $actualCostTotal = BigDecimal::zero();

        foreach ($components as $resolved) {
            if (! $resolved['sufficient']) {
                throw ValidationException::withMessages(['lines' => __('Insufficient component stock for SKU :sku.', ['sku' => $resolved['sku']])]);
            }

            $variant = ProductVariant::query()->with('product')->findOrFail((int) $resolved['product_variant_id']);
            $baseCost = (string) $variant->cost_price;
            if ($variant->tracking_mode === VariantTrackingMode::QuantityBased) {
                $quantity = $this->normalizeQuantity((string) $resolved['required']);
                $actualCostTotal = $actualCostTotal->plus(BigDecimal::of($baseCost)->multipliedBy($quantity));
                $line->components()->create([
                    'business_id' => $sale->business_id,
                    'product_variant_id' => $variant->id,
                    'sku_snapshot' => $variant->sku,
                    'name_snapshot' => $variant->product->name.' — '.$variant->name,
                    'quantity' => $quantity,
                    'unit_cost_snapshot' => $baseCost,
                ]);
                $operation->lines()->create([
                    'business_id' => $sale->business_id,
                    'product_variant_id' => $variant->id,
                    'quantity' => '-'.$quantity,
                    'unit_cost' => $baseCost,
                ]);
                $this->changeQuantity($operation, $location, $variant, '-'.$quantity, StockMovementType::Sale, $sale);

                continue;
            }

            $deviceCount = $this->quantityToMinor((string) $resolved['required']);
            if ($deviceCount % 1000 !== 0) {
                throw ValidationException::withMessages(['lines' => __('Serialized bundle components must resolve to whole devices.')]);
            }
            $devices = SerializedDevice::query()->where('business_id', $sale->business_id)
                ->where('product_variant_id', $variant->id)->where('location_id', $location->id)
                ->where('status', SerializedDeviceStatus::InStock->value)->orderBy('id')
                ->limit(intdiv($deviceCount, 1000))->lockForUpdate()->get();
            if ($devices->count() !== intdiv($deviceCount, 1000)) {
                throw ValidationException::withMessages(['lines' => __('A serialized combo component is no longer available.')]);
            }
            foreach ($devices as $device) {
                $actualCostTotal = $actualCostTotal->plus((string) $device->unit_cost);
                $line->components()->create([
                    'business_id' => $sale->business_id,
                    'product_variant_id' => $variant->id,
                    'serialized_device_id' => $device->id,
                    'sku_snapshot' => $variant->sku,
                    'name_snapshot' => $variant->product->name.' — '.$variant->name,
                    'imei_snapshot' => $device->imei,
                    'quantity' => '1.000',
                    'unit_cost_snapshot' => $device->unit_cost,
                ]);
                $this->markDeviceSold($user, $operation, $sale, $location, $device, $device->unit_selling_price ?? $variant->selling_price);
            }
        }

        $bundleUnitCost = $actualCostTotal->dividedBy((string) $line->quantity, 2, RoundingMode::HalfUp)->__toString();
        DB::table('sale_lines')->where('id', $line->id)->update(['cost_snapshot' => $bundleUnitCost]);
    }

    private function markDeviceSold(User $user, InventoryOperation $operation, Sale $sale, Location $location, SerializedDevice $device, string $sellingPrice): void
    {
        if ($device->location_id !== $location->id || $device->status !== SerializedDeviceStatus::InStock) {
            throw ValidationException::withMessages(['lines' => __('The selected serialized device is no longer available at this location.')]);
        }
        $operation->lines()->create([
            'business_id' => $sale->business_id,
            'product_variant_id' => $device->product_variant_id,
            'quantity' => '-1.000',
            'unit_cost' => $device->unit_cost,
            'unit_selling_price' => $sellingPrice,
            'condition' => $device->condition,
        ]);
        $device->events()->create([
            'business_id' => $sale->business_id,
            'inventory_operation_id' => $operation->id,
            'event_group_id' => $operation->operation_key,
            'event_type' => SerializedDeviceEventType::Sold,
            'from_location_id' => $location->id,
            'status_before' => $device->status,
            'status_after' => SerializedDeviceStatus::Sold,
            'reason' => $operation->reason,
            'notes' => $operation->notes,
            'performed_by' => $user->id,
        ]);
        $device->update(['status' => SerializedDeviceStatus::Sold]);
    }

    private function markDeviceReturned(User $user, InventoryOperation $operation, SaleReturn $saleReturn, Location $location, SerializedDevice $device, SerializedDeviceStatus $status = SerializedDeviceStatus::InStock): void
    {
        $operation->lines()->create([
            'business_id' => $saleReturn->business_id,
            'product_variant_id' => $device->product_variant_id,
            'quantity' => '1.000',
            'unit_cost' => $device->unit_cost,
            'unit_selling_price' => $device->unit_selling_price,
            'condition' => $device->condition,
        ]);
        $device->events()->create([
            'business_id' => $saleReturn->business_id,
            'inventory_operation_id' => $operation->id,
            'event_group_id' => $operation->operation_key,
            'event_type' => SerializedDeviceEventType::ReturnedFromSale,
            'from_location_id' => $location->id,
            'to_location_id' => $location->id,
            'status_before' => $device->status,
            'status_after' => $status,
            'reason' => $operation->reason,
            'notes' => $operation->notes,
            'performed_by' => $user->id,
        ]);
        $device->update(['status' => $status]);
    }

    /** @param array<string, mixed> $data */
    public function adjust(User $user, array $data): InventoryOperation
    {
        return DB::transaction(function () use ($user, $data): InventoryOperation {
            $business = $this->locationAccess->business();
            $operation = $this->existingOrNewOperation($business, $user, $data, InventoryOperationType::Adjustment);

            if ($operation->exists && $operation->lines()->exists()) {
                $this->authorizedLocation($user, (int) $data['location_id']);

                return $operation;
            }

            $location = $this->authorizedLocation($user, (int) $data['location_id']);
            $variant = $this->activeVariant($business, (int) $data['product_variant_id']);
            $this->assertQuantityVariant($variant);
            $quantity = $this->normalizeQuantity((string) $data['quantity']);
            if ($data['reason'] === 'damaged') {
                abort_unless($user->can('damaged-stock.create'), 403);
            }
            if ($data['reason'] === 'damaged' && $data['direction'] !== 'decrease') {
                throw ValidationException::withMessages(['direction' => __('Move damaged items out of available stock. Use Damaged stock to recover repaired items.')]);
            }
            $delta = $data['direction'] === 'decrease' ? '-'.$quantity : $quantity;
            $movementType = $data['direction'] === 'decrease' ? StockMovementType::AdjustmentOut : StockMovementType::AdjustmentIn;

            $operation->update(['destination_location_id' => $location->id]);
            $operation->lines()->create(['business_id' => $business->id, 'product_variant_id' => $variant->id, 'quantity' => $delta]);
            $this->changeQuantity($operation, $location, $variant, $delta, $movementType);
            if ($data['reason'] === 'damaged') {
                DamagedStockEntry::query()->create([
                    'business_id' => $business->id, 'location_id' => $location->id,
                    'product_variant_id' => $variant->id, 'inventory_operation_id' => $operation->id,
                    'quantity' => $quantity, 'remaining_quantity' => $quantity, 'unit_cost' => $variant->cost_price,
                    'reason' => 'Stock damaged', 'notes' => $data['notes'] ?? null, 'recorded_by' => $user->id,
                ]);
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function resolveDamage(User $user, DamagedStockEntry $entry, array $data): InventoryOperation
    {
        abort_unless($user->can($data['action'] === 'repair' ? 'damaged-stock.repair' : 'damaged-stock.write-off'), 403);

        return DB::transaction(function () use ($user, $entry, $data): InventoryOperation {
            $business = $this->locationAccess->business();
            $entry = DamagedStockEntry::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($entry->id);
            $location = $this->authorizedLocation($user, $entry->location_id);
            $payload = [...$data, 'damage_entry_id' => $entry->id, 'location_id' => $location->id, 'reference' => 'DAMAGE-'.$entry->id, 'reason' => $data['action'] === 'repair' ? 'Damaged stock repaired' : 'Damaged stock written off'];
            $operation = $this->existingOrNewOperation($business, $user, $payload, InventoryOperationType::Adjustment);
            if ($operation->lines()->exists()) {
                return $operation;
            }
            $quantity = $this->normalizeQuantity((string) $data['quantity']);
            if (BigDecimal::of($quantity)->isGreaterThan($entry->remaining_quantity)) {
                throw ValidationException::withMessages(['quantity' => __('Quantity exceeds the remaining damaged stock.')]);
            }
            $operation->update(['destination_location_id' => $location->id]);
            $operation->lines()->create([
                'business_id' => $business->id, 'product_variant_id' => $entry->product_variant_id,
                'quantity' => $data['action'] === 'repair' ? $quantity : '0.000',
            ]);
            if ($data['action'] === 'repair') {
                $variant = $this->activeVariant($business, $entry->product_variant_id);
                $this->changeQuantity($operation, $location, $variant, $quantity, StockMovementType::AdjustmentIn);
            }
            $entry->update(['remaining_quantity' => BigDecimal::of($entry->remaining_quantity)->minus($quantity)->toScale(3)->__toString()]);

            return $operation;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function transfer(User $user, array $data): InventoryOperation
    {
        return DB::transaction(function () use ($user, $data): InventoryOperation {
            $business = $this->locationAccess->business();
            $operation = $this->existingOrNewOperation($business, $user, $data, InventoryOperationType::Transfer);

            if ($operation->exists && ($operation->lines()->exists() || $operation->deviceEvents()->exists())) {
                if ($operation->source_location_id !== null) {
                    $this->authorizedLocation($user, $operation->source_location_id);
                }
                if ($operation->destination_location_id !== null) {
                    $this->authorizedLocation($user, $operation->destination_location_id);
                }

                return $operation;
            }

            $source = $this->authorizedLocation($user, (int) $data['source_location_id']);
            $destination = $this->authorizedLocation($user, (int) $data['destination_location_id']);

            if ($source->is($destination)) {
                throw ValidationException::withMessages(['destination_location_id' => __('Choose a different destination location.')]);
            }

            $operation->update(['source_location_id' => $source->id, 'destination_location_id' => $destination->id]);
            $items = $this->quantityTransferItems($data['items'] ?? []);
            $variants = [];

            foreach ($items as $index => $item) {
                $variant = $this->activeVariant($business, $item['product_variant_id']);
                $this->assertQuantityVariant($variant, "items.{$index}.product_variant_id");
                $variants[$variant->id] = $variant;
            }

            $this->lockTransferStockRows($business, [$source, $destination], array_values($variants));

            foreach ($items as $item) {
                $variant = $variants[$item['product_variant_id']];
                $quantity = $this->normalizeQuantity((string) $item['quantity']);
                $operation->lines()->create(['business_id' => $business->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity]);
                $this->changeQuantity($operation, $source, $variant, '-'.$quantity, StockMovementType::TransferOut);
                $this->changeQuantity($operation, $destination, $variant, $quantity, StockMovementType::TransferIn);
            }

            $deviceIds = $this->deviceIds($data['device_ids'] ?? []);
            $devices = SerializedDevice::query()
                ->where('business_id', $business->id)
                ->whereIn('id', $deviceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($devices->count() !== count($deviceIds)) {
                throw ValidationException::withMessages(['device_ids' => __('One or more devices are invalid.')]);
            }

            foreach ($devices->groupBy('product_variant_id') as $variantId => $variantDevices) {
                $operation->lines()->create([
                    'business_id' => $business->id,
                    'product_variant_id' => (int) $variantId,
                    'quantity' => number_format($variantDevices->count(), 3, '.', ''),
                ]);
            }

            foreach ($devices as $device) {
                if ($device->location_id !== $source->id || $device->status !== SerializedDeviceStatus::InStock) {
                    throw ValidationException::withMessages(['device_ids' => __('Every device must be in stock at the source location.')]);
                }

                $device->events()->create([
                    'business_id' => $business->id,
                    'inventory_operation_id' => $operation->id,
                    'event_group_id' => $operation->operation_key,
                    'event_type' => SerializedDeviceEventType::Transferred,
                    'from_location_id' => $source->id,
                    'to_location_id' => $destination->id,
                    'status_before' => $device->status,
                    'status_after' => $device->status,
                    'reason' => $operation->reason,
                    'notes' => $operation->notes,
                    'performed_by' => $user->id,
                ]);
                $device->update(['location_id' => $destination->id]);
            }

            return $operation->fresh(['lines']) ?? $operation;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function changeDeviceStatus(User $user, SerializedDevice $device, array $data): InventoryOperation
    {
        return DB::transaction(function () use ($user, $device, $data): InventoryOperation {
            $business = $this->locationAccess->business();
            $device = SerializedDevice::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($device->id);
            $this->authorizedLocation($user, $device->location_id);
            $operation = $this->existingOrNewOperation($business, $user, $data, InventoryOperationType::DeviceStatus);
            $newStatus = SerializedDeviceStatus::from($data['status']);

            if (in_array($device->status, [SerializedDeviceStatus::Sold, SerializedDeviceStatus::ReturnedToSupplier], true)) {
                throw ValidationException::withMessages(['status' => __('Sold and supplier-returned devices cannot be changed through inventory adjustment.')]);
            }

            if (! $operation->deviceEvents()->exists()) {
                $device->events()->create([
                    'business_id' => $business->id,
                    'inventory_operation_id' => $operation->id,
                    'event_group_id' => $operation->operation_key,
                    'event_type' => SerializedDeviceEventType::StatusChanged,
                    'from_location_id' => $device->location_id,
                    'to_location_id' => $device->location_id,
                    'status_before' => $device->status,
                    'status_after' => $newStatus,
                    'reason' => $operation->reason,
                    'notes' => $operation->notes,
                    'performed_by' => $user->id,
                ]);
                $device->update(['status' => $newStatus]);
            }

            return $operation;
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function resolveBundleAvailability(User $user, Location $location, ProductVariant $bundleVariant, string $multiplier = '1.000'): array
    {
        $this->authorizedLocation($user, $location->id);
        $bundleVariant->loadMissing('product', 'bundleComponents.componentVariant.product');

        if ($this->quantityToMinor($multiplier) <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Bundle quantity must be greater than zero.')]);
        }

        if ($bundleVariant->product->product_type !== ProductType::Combo) {
            throw ValidationException::withMessages(['product_variant_id' => __('The selected variant is not a combo.')]);
        }

        if ($bundleVariant->business_id !== $location->business_id
            || $bundleVariant->status !== CatalogStatus::Active
            || $bundleVariant->product->status !== CatalogStatus::Active
            || $bundleVariant->bundleComponents->isEmpty()) {
            throw ValidationException::withMessages(['product_variant_id' => __('The selected combo is unavailable or has no components.')]);
        }

        return array_values($bundleVariant->bundleComponents->map(function ($component) use ($location, $multiplier): array {
            $variant = $component->componentVariant;

            if ($variant->product->product_type === ProductType::Combo || $variant->status !== CatalogStatus::Active
                || $variant->product->status !== CatalogStatus::Active || $variant->business_id !== $location->business_id) {
                throw ValidationException::withMessages(['components' => __('The combo contains an inactive or nested combo component.')]);
            }

            $required = $this->multiplyQuantity((string) $component->quantity, $multiplier);
            if ($variant->tracking_mode === VariantTrackingMode::Serialized && $this->quantityToMinor($required) % 1000 !== 0) {
                throw ValidationException::withMessages(['components' => __('Serialized combo components must use whole-device quantities.')]);
            }
            $available = $variant->tracking_mode === VariantTrackingMode::Serialized
                ? (string) $variant->serializedDevices()->where('location_id', $location->id)->where('status', SerializedDeviceStatus::InStock->value)->count()
                : (string) ($variant->inventoryStocks()->where('location_id', $location->id)->value('quantity_on_hand') ?? '0');

            return [
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'required' => $required,
                'available' => $available,
                'sufficient' => $this->quantityToMinor($available) >= $this->quantityToMinor($required),
            ];
        })->values()->all());
    }

    /** @return list<array<string, mixed>> */
    public function assertBundleAvailability(User $user, Location $location, ProductVariant $bundleVariant, string $multiplier = '1.000'): array
    {
        $components = $this->resolveBundleAvailability($user, $location, $bundleVariant, $multiplier);

        foreach ($components as $component) {
            if (! $component['sufficient']) {
                throw ValidationException::withMessages([
                    'bundle' => __('Insufficient component stock for SKU :sku.', ['sku' => $component['sku']]),
                ]);
            }
        }

        return $components;
    }

    private function authorizedLocation(User $user, int $locationId): Location
    {
        $location = $this->locationAccess->accessibleQuery($user)->where('status', 'active')->find($locationId);

        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => __('The selected location is unavailable or unauthorized.')]);
        }

        return $location;
    }

    private function activeVariant(Business $business, int $variantId): ProductVariant
    {
        $variant = $business->productVariants()->with('product')->where('status', CatalogStatus::Active->value)->find($variantId);

        if ($variant === null || $variant->product->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['product_variant_id' => __('The selected product variant is unavailable.')]);
        }

        return $variant;
    }

    private function assertQuantityVariant(ProductVariant $variant, string $field = 'product_variant_id'): void
    {
        if ($variant->tracking_mode !== VariantTrackingMode::QuantityBased || $variant->product->product_type === ProductType::Combo) {
            throw ValidationException::withMessages([$field => __('Choose an active quantity-tracked, non-combo variant.')]);
        }
    }

    private function assertSerializedVariant(ProductVariant $variant): void
    {
        if ($variant->tracking_mode !== VariantTrackingMode::Serialized || $variant->product->product_type === ProductType::Combo) {
            throw ValidationException::withMessages(['product_variant_id' => __('Choose an active serialized variant.')]);
        }
    }

    /** @param array<string, mixed> $data */
    private function existingOrNewOperation(Business $business, User $user, array $data, InventoryOperationType $type): InventoryOperation
    {
        $payload = Arr::except($data, ['_token']);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = InventoryOperation::query()
            ->where('business_id', $business->id)
            ->where('operation_key', $data['operation_key'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->performed_by !== $user->id
                || ! hash_equals($existing->payload_hash, $hash)
                || $existing->type !== $type) {
                throw ValidationException::withMessages(['operation_key' => __('This submission key was already used for different stock data.')]);
            }

            return $existing;
        }

        return InventoryOperation::query()->create([
            'business_id' => $business->id,
            'operation_key' => $data['operation_key'],
            'payload_hash' => $hash,
            'type' => $type,
            'status' => 'posted',
            'reference' => $data['reference'] ?? null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'performed_by' => $user->id,
            'posted_at' => now(),
        ]);
    }

    private function changeQuantity(InventoryOperation $operation, Location $location, ProductVariant $variant, string $delta, StockMovementType $type, ?Model $reference = null): void
    {
        InventoryStock::query()->insertOrIgnore([
            'business_id' => $operation->business_id,
            'location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'quantity_on_hand' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stock = InventoryStock::query()
            ->where('location_id', $location->id)
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->firstOrFail();
        $beforeMinor = $this->quantityToMinor((string) $stock->quantity_on_hand);
        $afterMinor = $beforeMinor + $this->quantityToMinor($delta);

        if ($afterMinor < 0) {
            throw ValidationException::withMessages(['quantity' => __('Insufficient stock. This operation would make stock negative.')]);
        }

        $before = $this->minorToQuantity($beforeMinor);
        $after = $this->minorToQuantity($afterMinor);
        $stock->update(['quantity_on_hand' => $after]);
        StockMovement::query()->create([
            'business_id' => $operation->business_id,
            'location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'inventory_operation_id' => $operation->id,
            'movement_group_id' => $operation->operation_key,
            'movement_type' => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'quantity_delta' => $delta,
            'balance_before' => $before,
            'balance_after' => $after,
            'reason' => $operation->reason,
            'notes' => $operation->notes,
            'performed_by' => $operation->performed_by,
        ]);
    }

    /**
     * @param  list<Location>  $locations
     * @param  list<ProductVariant>  $variants
     */
    private function lockTransferStockRows(Business $business, array $locations, array $variants): void
    {
        $keys = [];

        foreach ($locations as $location) {
            foreach ($variants as $variant) {
                $keys[] = ['location_id' => $location->id, 'product_variant_id' => $variant->id];
            }
        }

        usort($keys, fn (array $left, array $right): int => [$left['location_id'], $left['product_variant_id']] <=> [$right['location_id'], $right['product_variant_id']]);

        foreach ($keys as $key) {
            InventoryStock::query()->insertOrIgnore([
                'business_id' => $business->id,
                'location_id' => $key['location_id'],
                'product_variant_id' => $key['product_variant_id'],
                'quantity_on_hand' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($keys as $key) {
            InventoryStock::query()
                ->where('location_id', $key['location_id'])
                ->where('product_variant_id', $key['product_variant_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /** @param array<string, mixed> $data */
    private function createDevice(InventoryOperation $operation, Location $location, ProductVariant $variant, User $user, array $data, SerializedDeviceEventType $eventType): SerializedDevice
    {
        $imei = $this->normalizeIdentifier((string) $data['imei']);
        $imei2 = filled($data['imei2'] ?? null) ? $this->normalizeIdentifier((string) $data['imei2']) : null;

        if ($imei2 === $imei) {
            throw ValidationException::withMessages(['devices' => __('Primary and secondary IMEI must be different.')]);
        }

        $identifiers = array_values(array_filter([$imei, $imei2]));
        if (DB::table('serialized_device_identifiers')->where('business_id', $operation->business_id)->whereIn('identifier', $identifiers)->exists()) {
            throw ValidationException::withMessages(['devices' => __('An IMEI in this batch has already been received.')]);
        }

        $device = SerializedDevice::query()->create([
            'business_id' => $operation->business_id,
            'product_variant_id' => $variant->id,
            'location_id' => $location->id,
            'received_operation_id' => $operation->id,
            'imei' => $imei,
            'imei2' => $imei2,
            'serial_number' => filled($data['serial_number'] ?? null) ? Str::upper(trim((string) $data['serial_number'])) : null,
            'condition' => $data['condition'],
            'status' => SerializedDeviceStatus::InStock,
            'unit_cost' => $data['unit_cost'],
            'unit_selling_price' => $data['unit_selling_price'] ?? null,
            'received_at' => now(),
            'created_by' => $user->id,
        ]);
        $device->identifiers()->create(['business_id' => $operation->business_id, 'identifier' => $imei, 'slot' => 'imei']);
        if ($imei2 !== null) {
            $device->identifiers()->create(['business_id' => $operation->business_id, 'identifier' => $imei2, 'slot' => 'imei2']);
        }
        $operation->lines()->create([
            'business_id' => $operation->business_id,
            'product_variant_id' => $variant->id,
            'quantity' => '1.000',
            'unit_cost' => $data['unit_cost'],
            'unit_selling_price' => $data['unit_selling_price'] ?? null,
            'condition' => $data['condition'],
        ]);
        $device->events()->create([
            'business_id' => $operation->business_id,
            'inventory_operation_id' => $operation->id,
            'event_group_id' => $operation->operation_key,
            'event_type' => $eventType,
            'to_location_id' => $location->id,
            'status_after' => SerializedDeviceStatus::InStock,
            'reason' => $operation->reason,
            'notes' => $operation->notes,
            'performed_by' => $user->id,
        ]);

        return $device;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return preg_replace('/\s+/', '', trim($identifier)) ?? trim($identifier);
    }

    private function normalizeQuantity(string $quantity): string
    {
        if (preg_match('/^\d+(?:\.\d{1,3})?$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => __('Enter a quantity with no more than three decimal places.')]);
        }

        $normalized = $this->quantityToMinor($quantity);

        if ($normalized <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must be greater than zero.')]);
        }

        return $this->minorToQuantity($normalized);
    }

    private function quantityToMinor(string $quantity): int
    {
        $negative = str_starts_with($quantity, '-');
        $quantity = ltrim($quantity, '+-');
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $minor = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$minor : $minor;
    }

    private function minorToQuantity(int $minor): string
    {
        $negative = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $negative.intdiv($minor, 1000).'.'.str_pad((string) ($minor % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function multiplyQuantity(string $quantity, string $multiplier): string
    {
        return $this->minorToQuantity((int) round(($this->quantityToMinor($quantity) * $this->quantityToMinor($multiplier)) / 1000));
    }

    /** @return list<array{product_variant_id: int, quantity: string}> */
    private function quantityTransferItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['product_variant_id'], $item['quantity'])) {
                continue;
            }

            $normalized[] = [
                'product_variant_id' => (int) $item['product_variant_id'],
                'quantity' => (string) $item['quantity'],
            ];
        }

        usort($normalized, fn (array $left, array $right): int => $left['product_variant_id'] <=> $right['product_variant_id']);

        return $normalized;
    }

    /** @return list<int> */
    private function deviceIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
        sort($normalized);

        return $normalized;
    }
}
