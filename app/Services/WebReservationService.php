<?php

namespace App\Services;

use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\SerializedDevice;
use App\Models\WebOrder;
use App\Models\WebOrderReservation;
use App\ProductType;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal inventory coordination; no storefront mutation route is registered. */
class WebReservationService
{
    /**
     * Internal allocation primitive for a future authorized order command. Combo callers pass
     * each underlying component, including repeated components, as a separate line key.
     */
    public function reserveComponent(WebOrder $order, int $itemId, int $componentVariantId, string $quantity, string $lineKey, ?int $deviceId = null): WebOrderReservation
    {
        return DB::transaction(function () use ($order, $itemId, $componentVariantId, $quantity, $lineKey, $deviceId): WebOrderReservation {
            $locked = $this->lockOrder($order);
            if ($locked->status !== 'pending_confirmation') {
                throw ValidationException::withMessages(['order' => __('This order cannot receive a new allocation.')]);
            }
            if ($locked->reservation_expires_at?->isPast()) {
                throw ValidationException::withMessages(['order' => __('This order allocation has expired.')]);
            }
            $amount = BigDecimal::of($quantity)->toScale(3);
            if (! $amount->isGreaterThan(BigDecimal::zero()) || strlen($lineKey) > 80 || $lineKey === '') {
                throw ValidationException::withMessages(['reservation' => __('Invalid reservation quantity or line key.')]);
            }
            $item = DB::table('web_order_items')->where('business_id', $locked->business_id)
                ->where('web_order_id', $locked->id)->where('id', $itemId)->lockForUpdate()->first();
            $variant = DB::table('product_variants as component')->join('products as product', 'product.id', '=', 'component.product_id')
                ->where('component.business_id', $locked->business_id)->where('component.id', $componentVariantId)
                ->where('product.business_id', $locked->business_id)
                ->first(['component.id', 'component.tracking_mode', 'product.product_type']);
            if ($item === null || $variant === null || $variant->product_type === ProductType::Combo->value
                || $variant->product_type === ProductType::Service->value) {
                throw ValidationException::withMessages(['reservation' => __('The order item or stock component is invalid.')]);
            }
            $sourceIsComponent = (int) $item->product_variant_id === $componentVariantId;
            $isComboComponent = DB::table('bundle_components')->where('business_id', $locked->business_id)
                ->where('bundle_variant_id', $item->product_variant_id)->where('component_variant_id', $componentVariantId)->exists();
            if (! $sourceIsComponent && ! $isComboComponent) {
                throw ValidationException::withMessages(['reservation' => __('This stock component does not belong to the order line.')]);
            }
            $existing = WebOrderReservation::query()->where('web_order_item_id', $itemId)
                ->where('reservation_line_key', $lineKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->status !== 'active' || $existing->component_variant_id !== $componentVariantId
                    || $existing->serialized_device_id !== $deviceId || BigDecimal::of((string) $existing->quantity)->compareTo($amount) !== 0) {
                    throw ValidationException::withMessages(['reservation' => __('This allocation key was already used for different stock.')]);
                }

                return $existing;
            }
            if ($variant->tracking_mode === VariantTrackingMode::Serialized->value) {
                if ($deviceId === null || $amount->compareTo('1.000') !== 0) {
                    throw ValidationException::withMessages(['reservation' => __('Serialized stock requires one exact device.')]);
                }
                $device = SerializedDevice::query()->where('business_id', $locked->business_id)->lockForUpdate()->findOrFail($deviceId);
                if ($device->location_id !== $locked->location_id || $device->product_variant_id !== $componentVariantId || $device->status->value !== 'in_stock') {
                    throw ValidationException::withMessages(['reservation' => __('The selected device is not available at this location.')]);
                }
                $this->assertDeviceFree($locked->business_id, $deviceId);
            } else {
                if ($deviceId !== null) {
                    throw ValidationException::withMessages(['reservation' => __('Quantity stock cannot reference a serialized device.')]);
                }
                InventoryStock::query()->insertOrIgnore([
                    'business_id' => $locked->business_id, 'location_id' => $locked->location_id,
                    'product_variant_id' => $componentVariantId, 'quantity_on_hand' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $stock = InventoryStock::query()->where('business_id', $locked->business_id)
                    ->where('location_id', $locked->location_id)->where('product_variant_id', $componentVariantId)
                    ->lockForUpdate()->firstOrFail();
                if (BigDecimal::of($this->freeQuantity((string) $stock->quantity_on_hand, $locked->business_id, $locked->location_id, $componentVariantId))->isLessThan($amount)) {
                    throw ValidationException::withMessages(['reservation' => __('Insufficient unreserved stock.')]);
                }
            }

            return WebOrderReservation::query()->create([
                'business_id' => $locked->business_id, 'web_order_id' => $locked->id, 'web_order_item_id' => $itemId,
                'location_id' => $locked->location_id, 'component_variant_id' => $componentVariantId,
                'serialized_device_id' => $deviceId, 'active_device_id' => $deviceId,
                'reservation_line_key' => $lineKey, 'quantity' => $amount->__toString(),
                'status' => 'active', 'expires_at' => $locked->reservation_expires_at,
            ]);
        }, 3);
    }

    public function activeQuantity(int $businessId, int $locationId, int $variantId): string
    {
        $query = DB::table('web_order_reservations')
            ->where('business_id', $businessId)->where('location_id', $locationId)
            ->where('component_variant_id', $variantId)->where('status', 'active')
            ->whereNull('serialized_device_id')->orderBy('id');
        $quantities = DB::transactionLevel() > 0
            ? $query->lockForUpdate()->pluck('quantity')
            : $query->pluck('quantity');
        $total = BigDecimal::zero();
        foreach ($quantities as $quantity) {
            $total = $total->plus((string) $quantity);
        }

        return $total->toScale(3)->__toString();
    }

    public function freeQuantity(string $onHand, int $businessId, int $locationId, int $variantId): string
    {
        return BigDecimal::of($onHand)->minus($this->activeQuantity($businessId, $locationId, $variantId))->toScale(3)->__toString();
    }

    /** Called after the business and physical stock rows are locked in the current transaction. */
    public function assertQuantityFloor(string $resultingOnHand, int $businessId, int $locationId, int $variantId): void
    {
        $this->requireTransaction();
        if (BigDecimal::of($resultingOnHand)->isLessThan($this->activeQuantity($businessId, $locationId, $variantId))) {
            throw ValidationException::withMessages(['quantity' => __('This stock is reserved for an online order.')]);
        }
    }

    public function hasActiveDevice(int $businessId, int $deviceId): bool
    {
        $query = DB::table('web_order_reservations')->where('business_id', $businessId)
            ->where('active_device_id', $deviceId)->where('status', 'active');

        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first(['id']) !== null;
    }

    public function hasActiveAtLocation(int $businessId, int $locationId): bool
    {
        $query = DB::table('web_order_reservations')->where('business_id', $businessId)
            ->where('location_id', $locationId)->where('status', 'active');

        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first(['id']) !== null;
    }

    /** @param Builder<SerializedDevice> $query
     * @return Builder<SerializedDevice>
     */
    public function excludeReservedDevices(Builder $query): Builder
    {
        return $query->whereNotExists(function ($subquery): void {
            $subquery->selectRaw('1')->from('web_order_reservations as active_web_reservations')
                ->whereColumn('active_web_reservations.active_device_id', 'serialized_devices.id')
                ->whereColumn('active_web_reservations.business_id', 'serialized_devices.business_id')
                ->where('active_web_reservations.status', 'active');
        });
    }

    /** Called after the device row is locked in the current business-scoped transaction. */
    public function assertDeviceFree(int $businessId, int $deviceId): void
    {
        $this->requireTransaction();
        if ($this->hasActiveDevice($businessId, $deviceId)) {
            throw ValidationException::withMessages(['device' => __('This serialized device is reserved for an online order.')]);
        }
    }

    /** Release after an authorized cancellation. State and reservations change together. */
    public function release(WebOrder $order, string $reason = 'cancelled', string $actorType = 'system', ?int $actorId = null): bool
    {
        return DB::transaction(function () use ($order, $reason, $actorType, $actorId): bool {
            $locked = $this->lockOrder($order);
            if (in_array($locked->status, ['cancelled', 'expired'], true)) {
                return false;
            }
            if ($locked->status !== 'pending_confirmation' || $locked->payment_method !== 'cod'
                || $locked->payment_status !== 'cod_due') {
                throw ValidationException::withMessages(['order' => __('This order cannot release its stock in the current state.')]);
            }
            $payment = DB::table('web_order_payments')->where('business_id', $locked->business_id)
                ->where('web_order_id', $locked->id)->lockForUpdate()->first(['method', 'status']);
            if ($payment === null || $payment->method !== 'cod' || $payment->status !== 'cod_due') {
                throw ValidationException::withMessages(['order' => __('This order requires payment reconciliation before cancellation.')]);
            }
            if (! $this->hasOnlyActiveReservations($locked)) {
                throw ValidationException::withMessages(['reservation' => __('The order allocation requires manual review before cancellation.')]);
            }
            $this->transition($locked, 'released');
            $this->changeOrderStatus($locked, 'cancelled', $reason, $actorType, $actorId);

            return true;
        }, 3);
    }

    /** A confirmed, paid or proof-submitted order is never expired by a late worker. */
    public function expire(WebOrder $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = $this->lockOrder($order);
            $reason = $this->expiryReason($locked);
            if ($reason === null || ! $this->hasOnlyActiveReservations($locked)) {
                return false;
            }
            $this->transition($locked, 'expired');
            $this->changeOrderStatus($locked, 'expired', $reason);

            return true;
        }, 3);
    }

    /** Called with the business and order locks held. Database time governs the deadline check. */
    private function expiryReason(WebOrder $order): ?string
    {
        if ($order->status !== 'pending_confirmation' || $order->reservation_expires_at === null) {
            return null;
        }
        $deadlineColumn = match ($order->payment_method) {
            'cod' => 'cod_confirmation_deadline_at', 'bank_transfer' => 'bank_proof_deadline_at', default => null,
        };
        if ($deadlineColumn === null || $order->{$deadlineColumn} === null) {
            return null;
        }
        $due = WebOrder::query()->whereKey($order->id)->where('business_id', $order->business_id)
            ->where($deadlineColumn, '<=', DB::raw('CURRENT_TIMESTAMP'))->exists();
        if (! $due) {
            return null;
        }
        $payment = DB::table('web_order_payments')->where('business_id', $order->business_id)
            ->where('web_order_id', $order->id)->lockForUpdate()->first(['method', 'status']);
        if ($payment === null || $payment->method !== $order->payment_method || $payment->status !== $order->payment_status) {
            return null;
        }
        if ($order->payment_method === 'cod' && $order->payment_status === 'cod_due') {
            return 'cod_confirmation_deadline';
        }
        if ($order->payment_method === 'bank_transfer' && $order->payment_status === 'transfer_awaiting_proof'
            && ! DB::table('web_bank_transfer_attempts')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->exists()) {
            return 'bank_proof_deadline';
        }

        return null;
    }

    private function hasOnlyActiveReservations(WebOrder $order): bool
    {
        $rows = $order->reservations()->orderBy('id')->lockForUpdate()->get(['status']);

        return $rows->isNotEmpty() && $rows->every(fn (WebOrderReservation $reservation): bool => $reservation->status === 'active');
    }

    /**
     * Trusted internal fulfillment boundary. The stock/device posting callback and reservation
     * transition share one transaction; a thrown exception rolls both back.
     *
     * @param  callable(WebOrder, Collection<int, WebOrderReservation>): void  $postStock
     */
    public function consumeForFulfillment(WebOrder $order, callable $postStock, string $actorType = 'system', ?int $actorId = null): bool
    {
        return $this->consumeWithStockCheck($order, $postStock, 'confirmed', 'fulfilled', $actorType, $actorId);
    }

    /** Handover caller owns the outer transaction and performs the ready/payment/pick checks. */
    public function consumeReadyForHandover(WebOrder $order, callable $postStock, int $actorId): void
    {
        $this->requireTransaction();
        $this->consumeWithStockCheck($order, $postStock, 'ready', null, 'staff', $actorId);
    }

    /** @param callable(WebOrder, Collection<int, WebOrderReservation>): void $postStock */
    private function consumeWithStockCheck(WebOrder $order, callable $postStock, string $expectedStatus,
        ?string $finalStatus, string $actorType, ?int $actorId): bool
    {
        return DB::transaction(function () use ($order, $postStock, $expectedStatus, $finalStatus, $actorType, $actorId): bool {
            $locked = $this->lockOrder($order);
            if ($finalStatus !== null && $locked->status === $finalStatus) {
                return false;
            }
            if ($locked->status !== $expectedStatus || ($locked->payment_method === 'bank_transfer' && $locked->payment_status !== 'paid_verified')) {
                throw ValidationException::withMessages(['order' => __('This order is not approved for fulfillment.')]);
            }
            $reservations = $locked->reservations()->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            if ($reservations->isEmpty()) {
                throw ValidationException::withMessages(['order' => __('This order has no active inventory allocation.')]);
            }
            $quantityPools = [];
            $deviceIds = [];
            foreach ($reservations as $reservation) {
                if ($reservation->serialized_device_id !== null) {
                    $deviceIds[] = $reservation->serialized_device_id;

                    continue;
                }
                $key = $reservation->location_id.':'.$reservation->component_variant_id;
                if (! isset($quantityPools[$key])) {
                    $quantityPools[$key] = [
                        'location_id' => $reservation->location_id,
                        'variant_id' => $reservation->component_variant_id,
                        'demand' => BigDecimal::zero(),
                    ];
                }
                $quantityPools[$key]['demand'] = $quantityPools[$key]['demand']->plus((string) $reservation->quantity);
            }
            ksort($quantityPools);
            $before = [];
            foreach ($quantityPools as $key => $pool) {
                $stock = InventoryStock::query()->where('business_id', $locked->business_id)
                    ->where('location_id', $pool['location_id'])->where('product_variant_id', $pool['variant_id'])
                    ->lockForUpdate()->firstOrFail();
                $before[$key] = BigDecimal::of((string) $stock->quantity_on_hand);
            }
            sort($deviceIds);
            foreach ($deviceIds as $deviceId) {
                $device = SerializedDevice::query()->where('business_id', $locked->business_id)
                    ->where('location_id', $locked->location_id)->lockForUpdate()->findOrFail($deviceId);
                if ($device->status->value !== 'in_stock') {
                    throw ValidationException::withMessages(['order' => __('A reserved device is no longer in stock.')]);
                }
            }
            $this->transition($locked, 'consumed');
            $reservations->each(fn (WebOrderReservation $row) => $row->setAttribute('status', 'consumed'));
            // The caller's own allocation no longer counts against its stock deduction.
            // A callback failure rolls this transition back with the physical mutation.
            $postStock($locked, $reservations);
            foreach ($quantityPools as $key => $pool) {
                $after = InventoryStock::query()->where('business_id', $locked->business_id)
                    ->where('location_id', $pool['location_id'])->where('product_variant_id', $pool['variant_id'])
                    ->lockForUpdate()->firstOrFail();
                $expected = $before[$key]->minus($pool['demand']);
                if (BigDecimal::of((string) $after->quantity_on_hand)->compareTo($expected) !== 0) {
                    throw ValidationException::withMessages(['order' => __('The fulfillment stock movement did not match its reservation.')]);
                }
                $this->assertQuantityFloor((string) $after->quantity_on_hand, $locked->business_id, $pool['location_id'], $pool['variant_id']);
            }
            foreach ($deviceIds as $deviceId) {
                $device = SerializedDevice::query()->where('business_id', $locked->business_id)->findOrFail($deviceId);
                if ($device->status->value !== 'sold') {
                    throw ValidationException::withMessages(['order' => __('The reserved device was not consumed by fulfillment.')]);
                }
            }
            if ($finalStatus !== null) {
                $this->changeOrderStatus($locked, $finalStatus, 'stock_consumed', $actorType, $actorId);
            }

            return true;
        }, 3);
    }

    private function lockOrder(WebOrder $order): WebOrder
    {
        $this->requireTransaction();
        Business::query()->whereKey($order->business_id)->lockForUpdate()->firstOrFail();

        return WebOrder::query()->where('business_id', $order->business_id)->lockForUpdate()->findOrFail($order->id);
    }

    private function transition(WebOrder $order, string $status): void
    {
        $order->reservations()->where('status', 'active')->update([
            'status' => $status, 'active_device_id' => null, 'transitioned_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function changeOrderStatus(WebOrder $order, string $status, string $reason, string $actorType = 'system', ?int $actorId = null): void
    {
        $before = $order->status;
        $order->update(['status' => $status]);
        DB::table('web_order_status_histories')->insert([
            'business_id' => $order->business_id, 'web_order_id' => $order->id,
            'from_status' => $before, 'to_status' => $status,
            'actor_type' => $actorType, 'actor_id' => $actorId,
            'reason_code' => $reason, 'created_at' => now(),
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reservation inventory checks require a database transaction.');
        }
    }
}
