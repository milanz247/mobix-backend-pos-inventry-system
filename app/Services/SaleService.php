<?php

namespace App\Services;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\SerializedDevice;
use App\Models\SerializedDeviceIdentifier;
use App\Models\User;
use App\ProductType;
use App\SalePaymentStatus;
use App\SaleStatus;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly LocationAccessService $locationAccess,
        private readonly InventoryService $inventory,
        private readonly CashierShiftService $shifts,
    ) {}

    /** @param array<string, mixed> $data */
    public function checkout(User $user, array $data, bool $requireActiveShift = false): Sale
    {
        try {
            return DB::transaction(function () use ($user, $data, $requireActiveShift): Sale {
                $business = $this->locationAccess->business();
                $location = $this->authorizedLocation($user, $business, (int) $data['location_id']);
                $shiftId = $this->shifts->activeId($user, $location->id);
                $payloadHash = $this->payloadHash(Arr::except($data, ['operation_key']));
                $existing = Sale::query()->where('business_id', $business->id)->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw ValidationException::withMessages(['operation_key' => __('This checkout key was already used for different cart data.')]);
                    }

                    $this->assertSaleLocation($user, $existing);

                    return $existing->load(['lines.components', 'payments']);
                }

                if ($requireActiveShift) {
                    abort_unless($user->can('shifts.view'), 403);
                    if ($shiftId === null) {
                        throw ValidationException::withMessages(['shift' => __('Open a cashier shift at the selected location before checkout.')]);
                    }
                }

                $preparedLines = $this->prepareLines($user, $business, $location, $data['lines']);
                $subtotal = BigDecimal::zero();
                $lineDiscounts = BigDecimal::zero();
                foreach ($preparedLines as $line) {
                    $subtotal = $subtotal->plus($line['subtotal']);
                    $lineDiscounts = $lineDiscounts->plus($line['discount_amount']);
                }
                $orderDiscount = $this->money((string) ($data['order_discount'] ?? '0'));
                $beforeOrderDiscount = $subtotal->minus($lineDiscounts);
                if (BigDecimal::of($orderDiscount)->isGreaterThan($beforeOrderDiscount)) {
                    throw ValidationException::withMessages(['order_discount' => __('The order discount cannot exceed the cart total.')]);
                }
                if (BigDecimal::of($orderDiscount)->isGreaterThan(BigDecimal::zero()) && ! $user->can('sales.discount')) {
                    abort(403);
                }

                $preparedLines = $this->allocateOrderDiscount($preparedLines, $orderDiscount, $beforeOrderDiscount);
                $discountTotal = $lineDiscounts->plus($orderDiscount)->toScale(2, RoundingMode::HalfUp)->__toString();
                $total = $beforeOrderDiscount->minus($orderDiscount)->toScale(2, RoundingMode::HalfUp)->__toString();
                $payments = $this->preparePayments($data['payments'] ?? []);
                $paidTotal = '0.00';
                foreach ($payments as $payment) {
                    $paidTotal = $this->money(BigDecimal::of($paidTotal)->plus($payment['amount'])->__toString());
                }
                if (BigDecimal::of($paidTotal)->isGreaterThan($total)) {
                    throw ValidationException::withMessages(['payments' => __('Payments cannot exceed the sale total.')]);
                }
                if (BigDecimal::of($paidTotal)->isLessThan($total) && ! $user->can('sales.credit')) {
                    throw ValidationException::withMessages(['payments' => __('This sale has a balance due. Credit sale permission is required.')]);
                }

                $sale = Sale::query()->create([
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'operation_key' => $data['operation_key'],
                    'payload_hash' => $payloadHash,
                    'cashier_id' => $user->id,
                    'cashier_shift_id' => $shiftId,
                    'source' => $data['source'],
                    'customer_name' => filled($data['customer_name'] ?? null) ? trim((string) $data['customer_name']) : __('Walk-in customer'),
                    'customer_phone' => filled($data['customer_phone'] ?? null) ? trim((string) $data['customer_phone']) : null,
                    'customer_email' => filled($data['customer_email'] ?? null) ? Str::lower(trim((string) $data['customer_email'])) : null,
                    'subtotal' => $subtotal->toScale(2, RoundingMode::HalfUp)->__toString(),
                    'discount_total' => $discountTotal,
                    'tax_total' => '0.00',
                    'total' => $total,
                    'paid_total' => $paidTotal,
                    'status' => SaleStatus::Completed,
                    'payment_status' => $this->paymentStatus($total, $paidTotal, '0.00'),
                    'notes' => $data['notes'] ?? null,
                    'posted_at' => now(),
                ]);
                $sale->update(['sale_number' => sprintf('SAL-%s-%07d', now()->format('Ymd'), $sale->id)]);

                foreach ($preparedLines as $line) {
                    $sale->lines()->create(Arr::except($line, ['device_identifier', 'variant']));
                }
                foreach ($payments as $payment) {
                    $sale->payments()->create([
                        'business_id' => $business->id,
                        'amount' => $payment['amount'],
                        'cash_tendered' => $payment['cash_tendered'],
                        'change_given' => $payment['change_given'],
                        'operation_key' => Str::uuid()->toString(),
                        'payload_hash' => $this->payloadHash($payment),
                        'method' => $payment['method'],
                        'reference' => $payment['reference'] ?? null,
                        'paid_at' => now(),
                        'received_by' => $user->id,
                        'cashier_shift_id' => $shiftId,
                    ]);
                }

                $this->inventory->postSale($user, $sale);

                return $sale->fresh(['location', 'cashier', 'lines.variant.product', 'lines.serializedDevice', 'lines.components', 'payments']) ?? $sale;
            }, 3);
        } catch (QueryException $exception) {
            $existing = Sale::query()->where('business_id', $this->locationAccess->business()->id)->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null && hash_equals($existing->payload_hash, $this->payloadHash(Arr::except($data, ['operation_key'])))) {
                $this->assertSaleLocation($user, $existing);

                return $existing->load(['lines.components', 'payments']);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function recordPayment(User $user, Sale $sale, array $data): SalePayment
    {
        try {
            return DB::transaction(function () use ($user, $sale, $data): SalePayment {
                $shiftId = $this->shifts->activeId($user, $sale->location_id);
                $business = $this->locationAccess->business();
                $sale = $business->sales()->lockForUpdate()->findOrFail($sale->id);
                $this->assertSaleLocation($user, $sale);
                $amount = $this->money((string) $data['amount']);
                $hash = $this->payloadHash(Arr::except($data, ['operation_key']));
                $existing = SalePayment::query()->where('business_id', $business->id)->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->sale_id !== $sale->id || ! hash_equals($existing->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['operation_key' => __('This payment key was already used for different payment data.')]);
                    }

                    return $existing;
                }
                if (BigDecimal::of($amount)->isGreaterThan($this->outstanding($sale))) {
                    throw ValidationException::withMessages(['amount' => __('The payment exceeds the outstanding balance.')]);
                }
                $cashDetails = $this->paymentCashDetails((string) $data['method'], $amount, $data['cash_tendered'] ?? null);

                $payment = $sale->payments()->create([
                    'business_id' => $business->id,
                    'amount' => $amount,
                    'cash_tendered' => $cashDetails['tendered'],
                    'change_given' => $cashDetails['change'],
                    'operation_key' => $data['operation_key'],
                    'payload_hash' => $hash,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'paid_at' => now(),
                    'received_by' => $user->id,
                    'cashier_shift_id' => $shiftId,
                ]);
                $sale->paid_total = $this->money(BigDecimal::of((string) $sale->paid_total)->plus($amount)->__toString());
                $sale->payment_status = $this->paymentStatus((string) $sale->total, (string) $sale->paid_total, $this->refundTotal($sale), $this->returnedTotal($sale));
                $sale->save();

                return $payment;
            }, 3);
        } catch (QueryException $exception) {
            $existing = SalePayment::query()->where('business_id', $this->locationAccess->business()->id)
                ->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null
                && $existing->sale_id === $sale->id
                && hash_equals($existing->payload_hash, $this->payloadHash(Arr::except($data, ['operation_key'])))) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function processReturn(User $user, Sale $sale, array $data): SaleReturn
    {
        try {
            return DB::transaction(function () use ($user, $sale, $data): SaleReturn {
                $shiftId = $this->shifts->activeId($user, $sale->location_id);
                $business = $this->locationAccess->business();
                $sale = $business->sales()->with(['lines.variant.product', 'lines.components.variant.product'])->lockForUpdate()->findOrFail($sale->id);
                $this->assertSaleLocation($user, $sale);
                $location = $this->authorizedLocation($user, $business, (int) $data['location_id']);
                if ($location->id !== $sale->location_id) {
                    throw ValidationException::withMessages(['location_id' => __('A sale must be returned to its original location.')]);
                }
                $payloadHash = $this->payloadHash(Arr::except($data, ['operation_key']));
                $existing = SaleReturn::query()->where('business_id', $business->id)->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->sale_id !== $sale->id || ! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw ValidationException::withMessages(['operation_key' => __('This return key was already used for different return data.')]);
                    }

                    return $existing->load(['lines', 'refunds']);
                }

                $returnLines = $this->prepareReturnLines($sale, $data['lines']);
                if ($returnLines === []) {
                    throw ValidationException::withMessages(['lines' => __('Select at least one item to return.')]);
                }
                $returnTotal = '0.00';
                foreach ($returnLines as $returnLine) {
                    if (! $returnLine['is_component']) {
                        $returnTotal = $this->money(BigDecimal::of($returnTotal)->plus($returnLine['total'])->__toString());
                    }
                }
                $refundAmount = $this->money((string) ($data['refund_amount'] ?? '0'));
                $refundedSoFar = BigDecimal::of($this->refundTotal($sale));
                $paidRemaining = BigDecimal::of((string) $sale->paid_total)->minus($refundedSoFar);
                $expectedRefund = BigDecimal::min($returnTotal, BigDecimal::max($paidRemaining, BigDecimal::zero()))->toScale(2, RoundingMode::HalfUp);
                if (BigDecimal::of($refundAmount)->compareTo($expectedRefund) !== 0) {
                    throw ValidationException::withMessages(['refund_amount' => __('Refund the return value up to the net amount already paid; unpaid returned value reduces the balance due.')]);
                }
                if (BigDecimal::of($refundAmount)->isGreaterThan(BigDecimal::zero()) && ! $user->can('sale-refunds.create')) {
                    abort(403);
                }

                $saleReturn = $sale->returns()->create([
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'operation_key' => $data['operation_key'],
                    'payload_hash' => $payloadHash,
                    'total' => $returnTotal,
                    'refund_total' => $refundAmount,
                    'reason' => trim((string) $data['reason']),
                    'notes' => $data['notes'] ?? null,
                    'processed_by' => $user->id,
                    'processed_at' => now(),
                ]);
                $saleReturn->update(['return_number' => sprintf('RET-%s-%07d', now()->format('Ymd'), $saleReturn->id)]);
                foreach ($returnLines as $line) {
                    $saleReturn->lines()->create([
                        'business_id' => $business->id,
                        ...Arr::except($line, ['variant']),
                    ]);
                }
                if (BigDecimal::of($refundAmount)->isGreaterThan(BigDecimal::zero())) {
                    $saleReturn->refunds()->create([
                        'business_id' => $business->id,
                        'sale_id' => $sale->id,
                        'amount' => $refundAmount,
                        'operation_key' => Str::uuid()->toString(),
                        'method' => $data['refund_method'],
                        'reference' => $data['refund_reference'] ?? null,
                        'refunded_at' => now(),
                        'processed_by' => $user->id,
                        'cashier_shift_id' => $shiftId,
                    ]);
                }
                $operation = $this->inventory->returnSale($user, $saleReturn);
                $saleReturn->update(['inventory_operation_id' => $operation->id]);
                $sale->refresh();
                $sale->status = $this->saleStatus($sale);
                $sale->payment_status = $this->paymentStatus((string) $sale->total, (string) $sale->paid_total, $this->refundTotal($sale), $this->returnedTotal($sale));
                $sale->save();

                return $saleReturn->fresh(['lines.saleLine', 'refunds', 'inventoryOperation']) ?? $saleReturn;
            }, 3);
        } catch (QueryException $exception) {
            $existing = SaleReturn::query()->where('business_id', $this->locationAccess->business()->id)
                ->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null
                && $existing->sale_id === $sale->id
                && hash_equals($existing->payload_hash, $this->payloadHash(Arr::except($data, ['operation_key'])))) {
                $this->assertSaleLocation($user, $sale);

                return $existing->load(['lines', 'refunds']);
            }

            throw $exception;
        }
    }

    public function scoped(User $user, Sale $sale): Sale
    {
        $business = $this->locationAccess->business();
        $sale = $business->sales()->with(['location', 'cashier'])->findOrFail($sale->id);
        $this->assertSaleLocation($user, $sale);

        return $sale;
    }

    public function outstanding(Sale $sale): BigDecimal
    {
        $returned = BigDecimal::of((string) $sale->returns()->sum('total'));
        $refunds = BigDecimal::of($this->refundTotal($sale));
        $netTotal = BigDecimal::max(BigDecimal::of((string) $sale->total)->minus($returned), BigDecimal::zero());
        $netPaid = BigDecimal::max(BigDecimal::of((string) $sale->paid_total)->minus($refunds), BigDecimal::zero());

        return BigDecimal::max($netTotal->minus($netPaid), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function prepareLines(User $user, Business $business, Location $location, array $items): array
    {
        $prepared = [];
        $seenVariants = [];
        foreach ($items as $index => $item) {
            $variant = $business->productVariants()->with('product', 'bundleComponents.componentVariant.product')
                ->where('status', CatalogStatus::Active->value)->findOrFail((int) $item['product_variant_id']);
            if ($variant->product->status !== CatalogStatus::Active) {
                throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => __('This product is inactive.')]);
            }
            $isCombo = $variant->product->product_type === ProductType::Combo;
            if (! $isCombo && $variant->tracking_mode === VariantTrackingMode::Serialized && BigDecimal::of((string) $item['quantity'])->compareTo('1') !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Each serialized phone must be added as one IMEI-tracked item.')]);
            }
            if ($isCombo && $variant->bundleComponents->isEmpty()) {
                throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => __('This combo has no active components.')]);
            }

            $device = null;
            $basePrice = (string) $variant->selling_price;
            $costSnapshot = (string) $variant->cost_price;
            if (! $isCombo && $variant->tracking_mode === VariantTrackingMode::Serialized) {
                $identifier = $this->normalizeIdentifier((string) ($item['device_identifier'] ?? ''));
                if ($identifier !== '') {
                    $identifierRow = SerializedDeviceIdentifier::query()->where('business_id', $business->id)
                        ->where('identifier', $identifier)->lockForUpdate()->first();
                    $device = $identifierRow?->device()->lockForUpdate()->first();
                } elseif (! $business->pos_require_imei_on_sale) {
                    $device = SerializedDevice::query()
                        ->where('business_id', $business->id)
                        ->where('product_variant_id', $variant->id)
                        ->where('location_id', $location->id)
                        ->where('status', 'in_stock')
                        ->oldest('id')
                        ->lockForUpdate()
                        ->first();
                }
                if ($device === null || $device->product_variant_id !== $variant->id || $device->location_id !== $location->id || $device->status->value !== 'in_stock') {
                    $message = $business->pos_require_imei_on_sale
                        ? __('Enter an IMEI or IMEI2 for an available phone at this location.')
                        : __('No available phone is in stock at this location.');

                    throw ValidationException::withMessages(["lines.{$index}.device_identifier" => $message]);
                }
                $basePrice = $device->unit_selling_price ?? $basePrice;
                $costSnapshot = $device->unit_cost;
            }
            if ($isCombo) {
                $costSnapshot = $this->bundleCost($variant);
            }

            $requestedPrice = filled($item['unit_price'] ?? null) ? $this->money((string) $item['unit_price']) : $basePrice;
            if (BigDecimal::of($requestedPrice)->isNegative()) {
                throw ValidationException::withMessages(["lines.{$index}.unit_price" => __('Price cannot be negative.')]);
            }
            if (BigDecimal::of($requestedPrice)->compareTo($basePrice) !== 0 && ! $business->pos_allow_price_override) {
                throw ValidationException::withMessages(["lines.{$index}.unit_price" => __('Unit price editing is disabled in POS settings.')]);
            }
            if (BigDecimal::of($requestedPrice)->compareTo($basePrice) !== 0 && ! $user->can('sales.price-override')) {
                throw ValidationException::withMessages(["lines.{$index}.unit_price" => __('Price override permission is required.')]);
            }
            if ($variant->minimum_selling_price !== null && BigDecimal::of($requestedPrice)->isLessThan($variant->minimum_selling_price) && ! $user->can('sales.price-override')) {
                throw ValidationException::withMessages(["lines.{$index}.unit_price" => __('The price is below the configured minimum selling price.')]);
            }
            $quantity = BigDecimal::of((string) $item['quantity'])->toScale(3, RoundingMode::Unnecessary)->__toString();
            $lineSubtotal = $this->money(BigDecimal::of($requestedPrice)->multipliedBy($quantity)->__toString());
            $discount = $this->money((string) ($item['discount_amount'] ?? '0'));
            if (BigDecimal::of($discount)->isGreaterThan($lineSubtotal)) {
                throw ValidationException::withMessages(["lines.{$index}.discount_amount" => __('Line discount cannot exceed the line subtotal.')]);
            }
            if (BigDecimal::of($discount)->isGreaterThan(BigDecimal::zero()) && ! $user->can('sales.discount')) {
                abort(403);
            }
            if ($isCombo) {
                foreach ($variant->bundleComponents as $component) {
                    if ($component->componentVariant->status !== CatalogStatus::Active || $component->componentVariant->product->status !== CatalogStatus::Active || $component->componentVariant->product->product_type === ProductType::Combo) {
                        throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => __('This combo contains an inactive or nested combo item.')]);
                    }
                }
            }
            if ($device !== null && isset($seenVariants[$device->id])) {
                throw ValidationException::withMessages(["lines.{$index}.device_identifier" => __('The same phone cannot be added to the cart more than once.')]);
            }
            if ($device !== null) {
                $seenVariants[$device->id] = true;
            }
            $prepared[] = [
                'business_id' => $business->id,
                'product_variant_id' => $variant->id,
                'serialized_device_id' => $device?->id,
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'sku_snapshot' => $variant->sku,
                'barcode_snapshot' => $variant->barcode,
                'tracking_mode' => $variant->tracking_mode,
                'is_combo' => $isCombo,
                'imei_snapshot' => $device?->imei,
                'quantity' => $quantity,
                'unit_price' => $requestedPrice,
                'discount_amount' => $discount,
                'cost_snapshot' => $costSnapshot,
                'subtotal' => $lineSubtotal,
                'total' => $this->money(BigDecimal::of($lineSubtotal)->minus($discount)->__toString()),
                'variant' => $variant,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function allocateOrderDiscount(array $lines, string $orderDiscount, BigDecimal $basis): array
    {
        if (BigDecimal::of($orderDiscount)->isZero()) {
            return $lines;
        }
        $remaining = BigDecimal::of($orderDiscount);
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => &$line) {
            $share = $index === $lastIndex
                ? $remaining
                : BigDecimal::of($orderDiscount)->multipliedBy((string) $line['total'])->dividedBy((string) $basis, 2, RoundingMode::HalfUp);
            $line['discount_amount'] = $this->money(BigDecimal::of($line['discount_amount'])->plus($share)->__toString());
            $line['total'] = $this->money(BigDecimal::of($line['total'])->minus($share)->__toString());
            $remaining = $remaining->minus($share);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{amount: string, method: string, reference: string|null, cash_tendered: string|null, change_given: string}>
     */
    private function preparePayments(array $payments): array
    {
        $prepared = [];
        foreach ($payments as $payment) {
            $amount = $this->money((string) $payment['amount']);
            $cashDetails = $this->paymentCashDetails((string) $payment['method'], $amount, $payment['cash_tendered'] ?? null);
            $prepared[] = [
                'amount' => $amount,
                'method' => (string) $payment['method'],
                'reference' => filled($payment['reference'] ?? null) ? trim((string) $payment['reference']) : null,
                'cash_tendered' => $cashDetails['tendered'],
                'change_given' => $cashDetails['change'],
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function prepareReturnLines(Sale $sale, array $items): array
    {
        $prepared = [];
        $seen = [];
        foreach ($items as $item) {
            $line = $sale->lines->firstWhere('id', (int) $item['sale_line_id']);
            if ($line === null || isset($seen[$line->id])) {
                throw ValidationException::withMessages(['lines' => __('Each selected item must belong to the original sale and appear once.')]);
            }
            $seen[$line->id] = true;
            $quantity = BigDecimal::of((string) $item['quantity'])->toScale(3, RoundingMode::Unnecessary);
            if ($quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages(['lines' => __('Return quantity must be greater than zero.')]);
            }
            $prior = BigDecimal::of((string) SaleReturnLine::query()->where('sale_line_id', $line->id)->where('is_component', false)->sum('quantity'));
            $available = BigDecimal::of((string) $line->quantity)->minus($prior);
            if ($quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages(['lines' => __('Return quantity exceeds the quantity sold and not yet returned.')]);
            }
            if ($line->serialized_device_id !== null && ! $quantity->isEqualTo('1')) {
                throw ValidationException::withMessages(['lines' => __('A serialized phone must be returned individually.')]);
            }

            $lineAmount = $this->returnValue($line, $quantity, $available);
            $prepared[] = [
                'sale_line_id' => $line->id,
                'sale_line_component_id' => null,
                'serialized_device_id' => $line->serialized_device_id,
                'is_component' => false,
                'restock' => (bool) $item['restock'],
                'quantity' => $quantity->__toString(),
                'unit_price' => $line->unit_price,
                'unit_cost_snapshot' => $line->cost_snapshot,
                'total' => $lineAmount,
                'variant' => $line->variant,
            ];

            if ($line->is_combo) {
                $ratio = $quantity->dividedBy((string) $line->quantity, 6, RoundingMode::HalfUp);
                $hasSerializedComponent = $line->components->contains(fn ($component): bool => $component->serialized_device_id !== null);
                if ($hasSerializedComponent && $quantity->compareTo((string) $line->quantity) !== 0) {
                    throw ValidationException::withMessages(['lines' => __('A combo containing serialized devices must be returned as a whole.')]);
                }
                foreach ($line->components as $component) {
                    $componentPrior = BigDecimal::of((string) SaleReturnLine::query()->where('sale_line_component_id', $component->id)->where('is_component', true)->sum('quantity'));
                    $componentQuantity = BigDecimal::of((string) $component->quantity)->multipliedBy((string) $ratio)->toScale(3, RoundingMode::HalfUp);
                    $componentAvailable = BigDecimal::of((string) $component->quantity)->minus($componentPrior);
                    if ($componentQuantity->isGreaterThan($componentAvailable)) {
                        throw ValidationException::withMessages(['lines' => __('A combo component was already returned.')]);
                    }
                    if ($component->serialized_device_id !== null && ! $componentQuantity->isEqualTo('1')) {
                        continue;
                    }
                    $prepared[] = [
                        'sale_line_id' => $line->id,
                        'sale_line_component_id' => $component->id,
                        'serialized_device_id' => $component->serialized_device_id,
                        'is_component' => true,
                        'restock' => (bool) $item['restock'],
                        'quantity' => $componentQuantity->__toString(),
                        'unit_price' => '0.00',
                        'unit_cost_snapshot' => $component->unit_cost_snapshot,
                        'total' => '0.00',
                        'variant' => $component->variant,
                    ];
                }
            }
        }

        return $prepared;
    }

    private function returnValue(SaleLine $line, BigDecimal $quantity, BigDecimal $available): string
    {
        $alreadyReturned = BigDecimal::of((string) $line->returnLines()->where('is_component', false)->sum('total'));
        $remainingValue = BigDecimal::of((string) $line->total)->minus($alreadyReturned);
        $amount = $quantity->isEqualTo($available)
            ? $remainingValue
            : BigDecimal::of((string) $line->total)->multipliedBy((string) $quantity)->dividedBy((string) $line->quantity, 2, RoundingMode::HalfUp);

        return $this->money(BigDecimal::min($amount, $remainingValue)->__toString());
    }

    private function bundleCost(ProductVariant $variant): string
    {
        $total = BigDecimal::zero();
        foreach ($variant->bundleComponents as $component) {
            $total = $total->plus(BigDecimal::of((string) $component->quantity)->multipliedBy((string) $component->componentVariant->cost_price));
        }

        return $this->money($total->__toString());
    }

    private function saleStatus(Sale $sale): SaleStatus
    {
        $fullyReturned = true;
        $hasReturn = false;
        foreach ($sale->lines()->with('components')->get() as $line) {
            $returned = BigDecimal::of((string) $line->returnLines()->where('is_component', false)->sum('quantity'));
            $hasReturn = $hasReturn || $returned->isGreaterThan(BigDecimal::zero());
            $fullyReturned = $fullyReturned && $returned->isGreaterThanOrEqualTo((string) $line->quantity);
        }

        return $fullyReturned ? SaleStatus::Returned : ($hasReturn ? SaleStatus::PartiallyReturned : SaleStatus::Completed);
    }

    private function paymentStatus(string $total, string $paidTotal, string $refundTotal, string $returnedTotal = '0.00'): SalePaymentStatus
    {
        $netTotal = BigDecimal::max(BigDecimal::of($total)->minus($returnedTotal), BigDecimal::zero());
        $netPaid = BigDecimal::max(BigDecimal::of($paidTotal)->minus($refundTotal), BigDecimal::zero());
        if ($netPaid->isGreaterThanOrEqualTo($netTotal)) {
            return SalePaymentStatus::Paid;
        }

        return $netPaid->isGreaterThan(BigDecimal::zero()) ? SalePaymentStatus::PartiallyPaid : SalePaymentStatus::Unpaid;
    }

    private function refundTotal(Sale $sale): string
    {
        return $this->money((string) $sale->refunds()->sum('amount'));
    }

    private function returnedTotal(Sale $sale): string
    {
        return $this->money((string) $sale->returns()->sum('total'));
    }

    private function authorizedLocation(User $user, Business $business, int $locationId): Location
    {
        $location = $this->locationAccess->accessibleQuery($user)->where('status', 'active')->find($locationId);
        if ($location === null || $location->business_id !== $business->id) {
            throw ValidationException::withMessages(['location_id' => __('The selected location is unavailable or unauthorized.')]);
        }

        return $location;
    }

    private function assertSaleLocation(User $user, Sale $sale): void
    {
        if (! $this->locationAccess->canAccess($user, $sale->location)) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function money(string $value): string
    {
        return BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    /** @return array{tendered: string|null, change: string} */
    private function paymentCashDetails(string $method, string $amount, mixed $cashTendered): array
    {
        if ($method !== 'cash') {
            if (filled($cashTendered)) {
                throw ValidationException::withMessages(['cash_tendered' => __('Cash received can only be recorded for a cash payment.')]);
            }

            return ['tendered' => null, 'change' => '0.00'];
        }

        $tendered = $this->money(filled($cashTendered) ? (string) $cashTendered : $amount);
        if (BigDecimal::of($tendered)->isLessThan($amount)) {
            throw ValidationException::withMessages(['cash_tendered' => __('Cash received cannot be less than the amount applied to the sale.')]);
        }

        return [
            'tendered' => $tendered,
            'change' => $this->money(BigDecimal::of($tendered)->minus($amount)->__toString()),
        ];
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($identifier)) ?? trim($identifier));
    }
}
