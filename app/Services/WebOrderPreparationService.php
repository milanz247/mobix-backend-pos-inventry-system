<?php

namespace App\Services;

use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebOrderOfferItem;
use App\Models\WebOrderReservation;
use App\PermissionName;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Staff-only preparation; no stock, payment or reservation mutation. */
class WebOrderPreparationService
{
    public function __construct(
        private readonly LocationAccessService $locations,
        private readonly WebOrderStaffService $staffOrders,
        private readonly WebOrderRecoveryService $recovery,
        private readonly WebReservationService $reservations,
    ) {}

    public function start(User $actor, string $reference): bool
    {
        abort_unless($actor->can(PermissionName::StorefrontPrepareOrders->value), 403);

        return DB::transaction(function () use ($actor, $reference): bool {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status === 'preparing') {
                return false;
            }
            if ($order->status !== 'confirmed') {
                $this->fail('Only a separately confirmed order can enter preparation.');
            }
            $this->assertAllocation($order);
            $order->update(['status' => 'preparing']);
            $this->history($order, 'confirmed', 'preparing', 'preparation_started', $actor);

            return true;
        }, 3);
    }

    /** @return array{result: string} */
    public function check(User $actor, string $reference, int $reservationId, string $checkedQuantity, ?string $deviceIdentifier, ?string $observedCondition, ?string $note): array
    {
        abort_unless($actor->can(PermissionName::StorefrontVerifyPicks->value), 403);

        return DB::transaction(function () use ($actor, $reference, $reservationId, $checkedQuantity, $deviceIdentifier, $observedCondition, $note): array {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status !== 'preparing') {
                $this->fail('This order is not being prepared.');
            }
            $this->assertAllocation($order, true);
            $reservation = $order->reservations()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            $expectedCondition = $this->acceptedCondition($order, $reservation);
            $quantityMatches = BigDecimal::of($checkedQuantity)->compareTo((string) $reservation->quantity) === 0;
            $identityMatches = true;
            $conditionMatches = true;
            if ($reservation->serialized_device_id !== null) {
                $device = SerializedDevice::query()->where('business_id', $order->business_id)
                    ->where('location_id', $order->location_id)->lockForUpdate()->find($reservation->serialized_device_id);
                $provided = mb_strtoupper(preg_replace('/\s+/', '', trim((string) $deviceIdentifier)) ?? '');
                $identifiers = $device === null ? [] : array_filter([$device->imei, $device->imei2, $device->serial_number]);
                $identityMatches = $device !== null && $device->status->value === 'in_stock' && $provided !== '' && in_array($provided,
                    array_map(fn (string $value): string => mb_strtoupper(preg_replace('/\s+/', '', $value) ?? ''), $identifiers), true);
                $conditionMatches = $device !== null && $expectedCondition !== null && $device->condition->value === $expectedCondition
                    && $observedCondition === $expectedCondition;
            }
            $result = $quantityMatches && $identityMatches && $conditionMatches ? 'matched' : 'discrepancy';
            if ($result === 'discrepancy' && mb_strlen(trim((string) $note)) < 5) {
                $this->fail('Explain the picking discrepancy (at least five characters).');
            }
            DB::table('web_order_fulfillment_checks')->insert([
                'business_id' => $order->business_id, 'web_order_id' => $order->id,
                'web_order_item_id' => $reservation->web_order_item_id,
                'web_order_reservation_id' => $reservation->id, 'result' => $result,
                'expected_quantity' => $reservation->quantity, 'checked_quantity' => $checkedQuantity,
                'condition_snapshot' => $observedCondition, 'internal_note' => $note,
                'performed_by' => $actor->id, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['result' => $result];
        }, 3);
    }

    public function markReady(User $actor, string $reference): bool
    {
        abort_unless($actor->can(PermissionName::StorefrontMarkReady->value), 403);

        return DB::transaction(function () use ($actor, $reference): bool {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status === 'ready') {
                return false;
            }
            if ($order->status !== 'preparing') {
                $this->fail('Only a prepared and separately confirmed order can be marked ready.');
            }
            $this->assertAllocation($order);
            $this->assertMatchingPickChecks($order);
            $order->update(['status' => 'ready']);
            $this->history($order, 'preparing', 'ready', 'picks_verified', $actor);

            return true;
        }, 3);
    }

    /** The handover caller holds the business and order locks. No state is changed here. */
    public function assertReadyForHandover(WebOrder $order): void
    {
        if ($order->status !== 'ready') {
            $this->fail('Only a ready order can be handed over.');
        }
        $this->assertAllocation($order);
        $this->assertMatchingPickChecks($order);
    }

    private function assertMatchingPickChecks(WebOrder $order): void
    {
        foreach ($order->reservations()->orderBy('id')->pluck('id') as $id) {
            $latest = DB::table('web_order_fulfillment_checks')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->where('web_order_reservation_id', $id)
                ->orderByDesc('id')->first(['result']);
            if ($latest?->result !== 'matched') {
                $this->fail('Every reserved component needs a current matching pick check. Resolve discrepancies before handover.');
            }
        }
    }

    private function lockOrder(User $actor, string $reference): WebOrder
    {
        $business = $this->locations->business();
        Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
        $order = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference)
            ->lockForUpdate()->firstOrFail();
        $location = Location::query()->where('business_id', $business->id)->findOrFail($order->location_id);
        abort_unless($this->locations->canAccess($actor, $location), 404);

        return $order;
    }

    private function assertAllocation(WebOrder $order, bool $allowDeviceDiscrepancy = false): void
    {
        $payment = DB::table('web_order_payments')->where('business_id', $order->business_id)
            ->where('web_order_id', $order->id)->lockForUpdate()->first(['method', 'status']);
        if ($payment === null || $payment->method !== $order->payment_method || $payment->status !== $order->payment_status) {
            $this->fail('Payment records require reconciliation before picking can continue.');
        }
        if ($order->payment_method === 'cod') {
            if ($order->payment_status !== 'cod_due' || DB::table('web_order_call_attempts')
                ->where('business_id', $order->business_id)->where('web_order_id', $order->id)
                ->orderByDesc('id')->value('outcome') !== 'answered_confirmed'
                || DB::table('web_order_status_histories')->where('business_id', $order->business_id)
                    ->where('web_order_id', $order->id)->where('to_status', 'confirmed')
                    ->where('reason_code', 'phone_confirmed')->doesntExist()) {
                $this->fail('A confirmed COD phone call and unpaid COD state are required.');
            }
        } elseif ($order->payment_method === 'bank_transfer') {
            if ($order->payment_status !== 'paid_verified' || DB::table('web_order_bank_confirmations')
                ->where('business_id', $order->business_id)->where('web_order_id', $order->id)->doesntExist()
                || DB::table('web_order_status_histories')->where('business_id', $order->business_id)
                    ->where('web_order_id', $order->id)->where('to_status', 'confirmed')
                    ->where('reason_code', 'bank_order_confirmed')->doesntExist()) {
                $this->fail('Bank payment verification and separate staff order confirmation are required.');
            }
        } else {
            $this->fail('This payment method cannot enter preparation.');
        }
        if ($order->reservation_expires_at === null || WebOrder::query()->whereKey($order->id)
            ->where('reservation_expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists()) {
            $this->fail('The reservation deadline has passed. An authorized review is required.');
        }
        if ($this->recovery->issues($order) !== []) {
            $this->fail('This order requires manual reconciliation before preparation can continue.');
        }
        $this->staffOrders->assertCompleteReservations($order, true);
        $rows = $order->reservations()->orderBy('location_id')->orderBy('component_variant_id')->orderBy('id')->lockForUpdate()->get();
        $pools = [];
        $devices = [];
        foreach ($rows as $row) {
            if ($row->serialized_device_id !== null) {
                $devices[$row->serialized_device_id] = $row;
            } else {
                $pools[$row->component_variant_id] = true;
            }
        }
        ksort($pools);
        foreach (array_keys($pools) as $variantId) {
            $stock = InventoryStock::query()->where('business_id', $order->business_id)
                ->where('location_id', $order->location_id)->where('product_variant_id', $variantId)
                ->lockForUpdate()->first();
            if ($stock === null) {
                $this->fail('A reserved stock pool is missing.');
            }
            $this->reservations->assertQuantityFloor((string) $stock->quantity_on_hand,
                $order->business_id, $order->location_id, $variantId);
        }
        ksort($devices);
        foreach ($devices as $deviceId => $row) {
            $device = SerializedDevice::query()->where('business_id', $order->business_id)
                ->where('location_id', $order->location_id)->lockForUpdate()->find($deviceId);
            if (! $allowDeviceDiscrepancy && ($device === null || $device->product_variant_id !== $row->component_variant_id
                || $device->status->value !== 'in_stock' || $device->condition->value !== $this->acceptedCondition($order, $row))) {
                $this->fail('An exact reserved device or its accepted condition changed.');
            }
        }
    }

    private function acceptedCondition(WebOrder $order, WebOrderReservation $reservation): ?string
    {
        $item = DB::table('web_order_items')->where('business_id', $order->business_id)
            ->where('web_order_id', $order->id)->where('id', $reservation->web_order_item_id)->first();
        if ($item === null) {
            $this->fail('The reserved order item is missing.');
        }
        if ($reservation->serialized_device_id === null) {
            return null;
        }
        $offer = WebOrderOfferItem::query()->where('business_id', $order->business_id)
            ->where('web_order_offer_id', $order->web_order_offer_id)
            ->where('line_number', $item->source_offer_line_number)->first();
        if ($offer === null || ! is_array($offer->private_allocation)) {
            $this->fail('The accepted device allocation is missing.');
        }
        if ($item->included_device_conditions_snapshot === null) {
            return $item->condition_snapshot;
        }
        $conditions = json_decode($item->included_device_conditions_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $index = 0;
        foreach ($offer->private_allocation as $part) {
            if (! is_array($part) || ! is_array($part['device_ids'] ?? null)) {
                continue;
            }
            foreach ($part['device_ids'] as $deviceId) {
                if ((int) $deviceId === $reservation->serialized_device_id) {
                    return $conditions[$index]['condition'] ?? null;
                }
                $index++;
            }
        }

        return null;
    }

    private function history(WebOrder $order, string $from, string $to, string $reason, User $actor): void
    {
        DB::table('web_order_status_histories')->insert([
            'business_id' => $order->business_id, 'web_order_id' => $order->id,
            'from_status' => $from, 'to_status' => $to, 'actor_type' => 'staff',
            'actor_id' => $actor->id, 'reason_code' => $reason, 'created_at' => now(),
        ]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['order' => $message]);
    }
}
