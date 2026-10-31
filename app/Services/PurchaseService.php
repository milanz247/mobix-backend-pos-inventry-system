<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Location;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Models\User;
use App\ProductType;
use App\PurchasePaymentStatus;
use App\PurchaseStatus;
use App\SupplierStatus;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly LocationAccessService $locationAccess,
        private readonly InventoryService $inventory,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDraft(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data): Purchase {
            $business = $this->locationAccess->business();
            $this->assertSupplier($business, (int) $data['supplier_id']);
            $this->assertLocation($user, (int) $data['location_id']);
            $purchase = $business->purchases()->create([
                'supplier_id' => (int) $data['supplier_id'],
                'location_id' => (int) $data['location_id'],
                'invoice_number' => trim((string) $data['invoice_number']),
                'operation_key' => Str::uuid()->toString(),
                'invoice_date' => $data['invoice_date'],
                'notes' => $data['notes'] ?? null,
                'status' => PurchaseStatus::Draft,
                'payment_status' => PurchasePaymentStatus::Unpaid,
                'created_by' => $user->id,
            ]);

            $this->replaceDraftLines($purchase, $business, $data['lines']);

            return $purchase->fresh(['supplier', 'location', 'lines.devices.purchaseLine.variant.product']) ?? $purchase;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $user, Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $data): Purchase {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPurchaseLocation($user, $purchase);
            $this->assertDraft($purchase);
            $this->assertSupplier($business, (int) $data['supplier_id']);
            $this->assertLocation($user, (int) $data['location_id']);

            $purchase->update(Arr::only($data, ['supplier_id', 'location_id', 'invoice_number', 'invoice_date', 'notes']));
            $purchase->lines()->delete();
            $this->replaceDraftLines($purchase, $business, $data['lines']);

            return $purchase->fresh(['supplier', 'location', 'lines.devices.purchaseLine.variant.product']) ?? $purchase;
        }, 3);
    }

    public function deleteDraft(User $user, Purchase $purchase): void
    {
        DB::transaction(function () use ($user, $purchase): void {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPurchaseLocation($user, $purchase);

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw ValidationException::withMessages([
                    'purchase' => __('Only draft purchase invoices can be deleted. Use a purchase return for received stock.'),
                ]);
            }

            $purchase->delete();
        }, 3);
    }

    public function post(User $user, Purchase $purchase): Purchase
    {
        return DB::transaction(function () use ($user, $purchase): Purchase {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->with(['supplier', 'location', 'lines.devices'])->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPurchaseLocation($user, $purchase);

            if ($purchase->status !== PurchaseStatus::Draft) {
                return $purchase;
            }

            $this->assertSupplier($business, $purchase->supplier_id);
            $this->assertLocation($user, $purchase->location_id);
            $operation = $this->inventory->receivePurchase($user, $purchase);

            $purchase->update([
                'inventory_operation_id' => $operation->id,
                'status' => PurchaseStatus::Posted,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ]);

            return $purchase->fresh(['supplier', 'location', 'lines.variant.product', 'inventoryOperation']) ?? $purchase;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordPayment(User $user, Purchase $purchase, array $data): PurchasePayment
    {
        return DB::transaction(function () use ($user, $purchase, $data): PurchasePayment {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPurchaseLocation($user, $purchase);

            if ($purchase->status === PurchaseStatus::Draft) {
                throw ValidationException::withMessages(['amount' => __('Payments can only be recorded for posted purchases.')]);
            }

            $amount = $this->money((string) $data['amount']);
            $paymentPayload = Arr::only($data, ['payment_date', 'method', 'reference', 'notes']);
            $paymentPayload['amount'] = $amount;
            $payloadHash = hash('sha256', json_encode($paymentPayload, JSON_THROW_ON_ERROR));
            $existingPayment = PurchasePayment::query()
                ->where('business_id', $business->id)
                ->where('operation_key', $data['operation_key'])
                ->lockForUpdate()
                ->first();
            if ($existingPayment !== null) {
                if ($existingPayment->purchase_id !== $purchase->id || ! hash_equals($existingPayment->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['operation_key' => __('This payment submission key was already used for different payment data.')]);
                }

                return $existingPayment;
            }

            $outstanding = $this->outstandingAmount($purchase);
            if (BigDecimal::of($amount)->isGreaterThan($outstanding)) {
                throw ValidationException::withMessages(['amount' => __('The payment cannot exceed the outstanding balance of :amount.', ['amount' => (string) $outstanding])]);
            }

            $payment = $purchase->payments()->create([
                'business_id' => $business->id,
                'amount' => $amount,
                'operation_key' => $data['operation_key'],
                'payload_hash' => $payloadHash,
                'payment_date' => $data['payment_date'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $user->id,
            ]);
            $purchase->setAttribute('paid_total', $this->money(BigDecimal::of((string) $purchase->paid_total)->plus($amount)->toScale(2, RoundingMode::HalfUp)->__toString()));
            $purchase->payment_status = $this->paymentStatus($purchase, (string) $purchase->paid_total);
            $purchase->save();

            return $payment;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createReturn(User $user, Purchase $purchase, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($user, $purchase, $data): PurchaseReturn {
            $business = $this->locationAccess->business();
            $purchase = $business->purchases()->with(['lines.devices.serializedDevice'])->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPurchaseLocation($user, $purchase);

            if ($purchase->status === PurchaseStatus::Draft || $purchase->inventory_operation_id === null) {
                throw ValidationException::withMessages(['purchase' => __('Only posted purchases can be returned.')]);
            }

            $returnPayload = Arr::only($data, ['location_id', 'return_number', 'return_date', 'notes', 'lines']);
            $payloadHash = hash('sha256', json_encode($returnPayload, JSON_THROW_ON_ERROR));
            $existingReturn = PurchaseReturn::query()
                ->where('business_id', $business->id)
                ->where('operation_key', $data['operation_key'])
                ->lockForUpdate()
                ->first();
            if ($existingReturn !== null) {
                if ($existingReturn->purchase_id !== $purchase->id || ! hash_equals($existingReturn->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['operation_key' => __('This return submission key was already used for different return data.')]);
                }

                return $existingReturn;
            }

            if ($business->purchases()->whereHas('returns', fn ($query) => $query->where('return_number', $data['return_number']))->exists()) {
                throw ValidationException::withMessages(['return_number' => __('This return number is already used.')]);
            }

            $location = $this->assertLocation($user, (int) $data['location_id']);
            $returnLines = $this->prepareReturnLines($business, $purchase, $location, $data['lines']);
            $total = '0.00';
            foreach ($returnLines as $line) {
                $total = BigDecimal::of($total)->plus($line['total'])->toScale(2, RoundingMode::HalfUp)->__toString();
            }

            $purchaseReturn = $purchase->returns()->create([
                'business_id' => $business->id,
                'location_id' => $location->id,
                'return_number' => trim((string) $data['return_number']),
                'return_date' => $data['return_date'],
                'status' => 'posted',
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'operation_key' => $data['operation_key'],
                'payload_hash' => $payloadHash,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ]);

            $operation = $this->inventory->returnPurchase($user, $purchase, $purchaseReturn, $returnLines);

            foreach ($returnLines as $line) {
                $purchaseReturn->lines()->create([
                    'business_id' => $business->id,
                    'purchase_line_id' => $line['purchase_line_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'serialized_device_id' => $line['serialized_device_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total' => $line['total'],
                ]);
            }

            $purchaseReturn->update(['inventory_operation_id' => $operation->id]);
            $purchase->refresh();
            $purchase->status = $this->purchaseStatusAfterReturn($purchase);
            $purchase->payment_status = $this->paymentStatus($purchase, (string) $purchase->paid_total);
            $purchase->save();

            return $purchaseReturn->fresh(['lines.variant.product', 'location', 'inventoryOperation']) ?? $purchaseReturn;
        }, 3);
    }

    public function scoped(User $user, Purchase $purchase): Purchase
    {
        $business = $this->locationAccess->business();
        $purchase = $business->purchases()->with(['location', 'supplier'])->findOrFail($purchase->id);
        $this->assertPurchaseLocation($user, $purchase);

        return $purchase;
    }

    public function outstandingAmount(Purchase $purchase): BigDecimal
    {
        $returns = $purchase->returns()->where('status', 'posted')->sum('total');

        return BigDecimal::max(
            BigDecimal::of((string) $purchase->total)
                ->minus((string) $purchase->paid_total)
                ->minus((string) $returns)
                ->toScale(2, RoundingMode::HalfUp),
            BigDecimal::zero(),
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceDraftLines(Purchase $purchase, Business $business, array $lines): void
    {
        $subtotal = BigDecimal::zero();
        $discountTotal = BigDecimal::zero();

        foreach ($lines as $lineData) {
            $variant = $business->productVariants()->with('product')->findOrFail((int) $lineData['product_variant_id']);
            $trackingMode = $variant->tracking_mode;
            $devices = $lineData['devices'] ?? [];

            if ($variant->status->value !== 'active' || $variant->product->status->value !== 'active' || in_array($variant->product->product_type, [ProductType::Combo, ProductType::Service], true)) {
                throw ValidationException::withMessages(['lines' => __('Only active, stock-tracked variants can be purchased.')]);
            }

            if ($trackingMode === VariantTrackingMode::QuantityBased) {
                $quantity = BigDecimal::of((string) $lineData['quantity'])->toScale(3, RoundingMode::Unnecessary);
                $unitCost = $this->money((string) $lineData['unit_cost']);
                $lineSubtotal = BigDecimal::of((string) $quantity)->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp);
            } else {
                $quantity = BigDecimal::of((string) count($devices))->toScale(3);
                $lineSubtotal = BigDecimal::zero();
                foreach ($devices as $device) {
                    $lineSubtotal = $lineSubtotal->plus($this->money((string) $device['unit_cost']));
                }
                $unitCost = $lineSubtotal->dividedBy(count($devices), 2, RoundingMode::HalfUp)->__toString();
            }

            $discount = $this->money((string) ($lineData['discount_amount'] ?? '0'));
            if (BigDecimal::of($discount)->isGreaterThan($lineSubtotal)) {
                throw ValidationException::withMessages(['lines' => __('A line discount cannot exceed the line subtotal.')]);
            }

            $lineTotal = $lineSubtotal->minus($discount)->toScale(2, RoundingMode::HalfUp);
            $purchaseLine = $purchase->lines()->create([
                'business_id' => $business->id,
                'product_variant_id' => $variant->id,
                'tracking_mode' => $trackingMode,
                'quantity' => (string) $quantity,
                'unit_cost' => $unitCost,
                'subtotal' => $lineSubtotal->toScale(2, RoundingMode::HalfUp)->__toString(),
                'discount_amount' => $discount,
                'total' => $lineTotal->__toString(),
            ]);

            if ($trackingMode === VariantTrackingMode::Serialized) {
                foreach ($devices as $device) {
                    $purchaseLine->devices()->create([
                        'business_id' => $business->id,
                        'imei' => $this->normalizeIdentifier((string) $device['imei']),
                        'imei2' => filled($device['imei2'] ?? null) ? $this->normalizeIdentifier((string) $device['imei2']) : null,
                        'serial_number' => filled($device['serial_number'] ?? null) ? trim((string) $device['serial_number']) : null,
                        'condition' => $device['condition'],
                        'unit_cost' => $this->money((string) $device['unit_cost']),
                        'unit_selling_price' => filled($device['unit_selling_price'] ?? null) ? $this->money((string) $device['unit_selling_price']) : null,
                    ]);
                }
            }

            $subtotal = $subtotal->plus($lineSubtotal);
            $discountTotal = $discountTotal->plus($discount);
        }

        $purchase->update([
            'subtotal' => $subtotal->toScale(2, RoundingMode::HalfUp)->__toString(),
            'discount_total' => $discountTotal->toScale(2, RoundingMode::HalfUp)->__toString(),
            'total' => $subtotal->minus($discountTotal)->toScale(2, RoundingMode::HalfUp)->__toString(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function prepareReturnLines(Business $business, Purchase $purchase, Location $location, array $lines): array
    {
        $prepared = [];
        $seenLines = [];
        $seenDevices = [];

        foreach ($lines as $lineData) {
            $purchaseLineId = (int) $lineData['purchase_line_id'];
            if (isset($seenLines[$purchaseLineId])) {
                throw ValidationException::withMessages(['lines' => __('Each purchase line can appear only once in a return.')]);
            }
            $seenLines[$purchaseLineId] = true;
            $purchaseLine = $purchase->lines->firstWhere('id', $purchaseLineId);
            if ($purchaseLine === null) {
                throw ValidationException::withMessages(['lines' => __('A return line does not belong to this purchase.')]);
            }

            if ($purchaseLine->tracking_mode === VariantTrackingMode::QuantityBased) {
                $quantity = BigDecimal::of((string) ($lineData['quantity'] ?? '0'))->toScale(3, RoundingMode::Unnecessary);
                $returnedQuantity = BigDecimal::of((string) PurchaseReturnLine::query()
                    ->where('purchase_line_id', $purchaseLine->id)->sum('quantity'));
                $available = BigDecimal::of((string) $purchaseLine->quantity)->minus($returnedQuantity);
                if ($quantity->isGreaterThan($available)) {
                    throw ValidationException::withMessages(['lines' => __('The return quantity exceeds the unreturned purchase quantity.')]);
                }
                $returnedValue = BigDecimal::of((string) PurchaseReturnLine::query()
                    ->where('purchase_line_id', $purchaseLine->id)->sum('total'));
                $remainingValue = BigDecimal::of((string) $purchaseLine->total)->minus($returnedValue);
                $total = $quantity->isEqualTo($available)
                    ? $remainingValue
                    : BigDecimal::of((string) $purchaseLine->total)
                        ->multipliedBy((string) $quantity)
                        ->dividedBy((string) $purchaseLine->quantity, 2, RoundingMode::HalfUp);
                if ($total->isGreaterThan($remainingValue)) {
                    $total = $remainingValue;
                }
                $unitCost = (string) $purchaseLine->unit_cost;
                $prepared[] = [
                    'purchase_line_id' => $purchaseLine->id,
                    'product_variant_id' => $purchaseLine->product_variant_id,
                    'serialized_device_id' => null,
                    'quantity' => (string) $quantity,
                    'unit_cost' => $unitCost,
                    'total' => $total->toScale(2, RoundingMode::HalfUp)->__toString(),
                ];

                continue;
            }

            $deviceIds = array_values(array_unique(array_map('intval', $lineData['serialized_device_ids'] ?? [])));
            if ($deviceIds === []) {
                throw ValidationException::withMessages(['lines' => __('Select the serialized devices being returned.')]);
            }

            foreach ($deviceIds as $deviceId) {
                if (isset($seenDevices[$deviceId])) {
                    throw ValidationException::withMessages(['lines' => __('A device can be returned only once.')]);
                }
                $seenDevices[$deviceId] = true;
                $draftDevice = $purchaseLine->devices->firstWhere('serialized_device_id', $deviceId);
                $device = $draftDevice?->serializedDevice;
                if ($device === null || $device->business_id !== $business->id || $device->location_id !== $location->id || $device->status->value !== 'in_stock') {
                    throw ValidationException::withMessages(['lines' => __('Only unreturned devices in stock at the selected location can be returned.')]);
                }
                $unitCost = (string) $device->unit_cost;
                $prepared[] = [
                    'purchase_line_id' => $purchaseLine->id,
                    'product_variant_id' => $purchaseLine->product_variant_id,
                    'serialized_device_id' => $device->id,
                    'quantity' => '1.000',
                    'unit_cost' => $unitCost,
                    'total' => $unitCost,
                ];
            }
        }

        return $prepared;
    }

    private function purchaseStatusAfterReturn(Purchase $purchase): PurchaseStatus
    {
        $hasReturn = false;
        $fullyReturned = true;

        foreach ($purchase->lines as $line) {
            $returned = BigDecimal::of((string) PurchaseReturnLine::query()->where('purchase_line_id', $line->id)->sum('quantity'));
            $quantity = BigDecimal::of((string) $line->quantity);
            $hasReturn = $hasReturn || $returned->isGreaterThan(BigDecimal::zero());
            $fullyReturned = $fullyReturned && $returned->isGreaterThanOrEqualTo($quantity);
        }

        return $fullyReturned ? PurchaseStatus::Returned : ($hasReturn ? PurchaseStatus::PartiallyReturned : PurchaseStatus::Posted);
    }

    private function paymentStatus(Purchase $purchase, string $paidTotal): PurchasePaymentStatus
    {
        $payable = BigDecimal::max(
            BigDecimal::of((string) $purchase->total)
                ->minus((string) $purchase->returns()->where('status', 'posted')->sum('total')),
            BigDecimal::zero(),
        );

        if (BigDecimal::of($paidTotal)->isGreaterThanOrEqualTo($payable)) {
            return PurchasePaymentStatus::Paid;
        }

        return BigDecimal::of($paidTotal)->isGreaterThan(BigDecimal::zero())
            ? PurchasePaymentStatus::PartiallyPaid
            : PurchasePaymentStatus::Unpaid;
    }

    private function assertSupplier(Business $business, int $supplierId): Supplier
    {
        return $business->suppliers()->where('status', SupplierStatus::Active->value)->findOrFail($supplierId);
    }

    private function assertLocation(User $user, int $locationId): Location
    {
        $location = $this->locationAccess->accessibleQuery($user)->where('status', 'active')->find($locationId);
        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => __('The selected location is unavailable or unauthorized.')]);
        }

        return $location;
    }

    private function assertPurchaseLocation(User $user, Purchase $purchase): void
    {
        if (! $this->locationAccess->canAccess($user, $purchase->location)) {
            throw (new ModelNotFoundException)->setModel(Purchase::class, [$purchase->id]);
        }
    }

    private function assertDraft(Purchase $purchase): void
    {
        if ($purchase->status !== PurchaseStatus::Draft) {
            throw ValidationException::withMessages(['purchase' => __('Only draft purchases can be edited.')]);
        }
    }

    private function money(string $amount): string
    {
        return BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return preg_replace('/\s+/', '', trim($identifier)) ?? trim($identifier);
    }
}
