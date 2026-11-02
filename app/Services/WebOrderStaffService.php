<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebOrderOfferItem;
use App\PermissionName;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebOrderStaffService
{
    public function __construct(private readonly LocationAccessService $locations, private readonly WebReservationService $reservations) {}

    public function recordCall(User $actor, string $reference, string $outcome, ?string $note): void
    {
        abort_unless($actor->can(PermissionName::StorefrontConfirmOrders->value), 403);
        DB::transaction(function () use ($actor, $reference, $outcome, $note): void {
            $order = $this->lockOrder($actor, $reference);
            $this->assertPendingCod($order, true);
            DB::table('web_order_call_attempts')->insert([
                'business_id' => $order->business_id, 'web_order_id' => $order->id,
                'order_reference_snapshot' => $order->reference, 'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'outcome' => $outcome,
                'internal_note' => $note, 'created_at' => now(),
            ]);
        }, 3);
    }

    /** Return false for a duplicate confirmed order; no duplicate history is written. */
    public function confirmCod(User $actor, string $reference): bool
    {
        abort_unless($actor->can(PermissionName::StorefrontConfirmOrders->value), 403);

        return DB::transaction(function () use ($actor, $reference): bool {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status === 'confirmed' && $order->payment_method === 'cod' && $order->payment_status === 'cod_due') {
                return false;
            }
            $this->assertPendingCod($order, true);
            $this->assertCompleteReservations($order, true);
            if ($this->latestOutcome($order) !== 'answered_confirmed') {
                throw ValidationException::withMessages(['call' => 'Record a customer-confirmed phone call before confirming this order.']);
            }
            $order->update(['status' => 'confirmed']);
            DB::table('web_order_status_histories')->insert([
                'business_id' => $order->business_id, 'web_order_id' => $order->id,
                'from_status' => 'pending_confirmation', 'to_status' => 'confirmed',
                'actor_type' => 'staff', 'actor_id' => $actor->id,
                'reason_code' => 'phone_confirmed', 'created_at' => now(),
            ]);

            return true;
        }, 3);
    }

    /** Payment verification is a separate earlier event; this action changes order status only. */
    public function confirmBank(User $actor, string $reference, ?string $internalNote): bool
    {
        abort_unless($actor->can(PermissionName::StorefrontConfirmBankOrders->value)
            && $actor->can(PermissionName::StorefrontViewPaymentProofs->value), 403);

        return DB::transaction(function () use ($actor, $reference, $internalNote): bool {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status === 'confirmed' && $order->payment_method === 'bank_transfer') {
                if (DB::table('web_order_bank_confirmations')->where('business_id', $order->business_id)
                    ->where('web_order_id', $order->id)->exists()) {
                    return false;
                }
                throw ValidationException::withMessages(['order' => 'This confirmed bank order has no confirmation audit and requires review.']);
            }
            if ($order->status !== 'pending_confirmation' || $order->payment_method !== 'bank_transfer'
                || $order->payment_status !== 'paid_verified') {
                throw ValidationException::withMessages(['order' => 'Only a pending bank transfer order with verified payment can be confirmed.']);
            }
            $payment = DB::table('web_order_payments')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->lockForUpdate()->first(['id', 'method', 'status', 'amount_due', 'currency']);
            if ($payment === null || $payment->method !== 'bank_transfer' || $payment->status !== 'paid_verified'
                || $payment->currency !== $order->currency || BigDecimal::of((string) $payment->amount_due)->compareTo((string) $order->total) !== 0) {
                throw ValidationException::withMessages(['order' => 'The payment obligation requires reconciliation before confirmation.']);
            }
            $decisions = DB::table('web_bank_transfer_decisions')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->orderBy('id')->lockForUpdate()->get(['decision', 'received_amount']);
            $received = BigDecimal::zero();
            $verifiedCount = 0;
            foreach ($decisions as $decision) {
                if (in_array($decision->decision, ['overpayment_review'], true)) {
                    throw ValidationException::withMessages(['order' => 'An unresolved bank payment exception requires manual review.']);
                }
                if (in_array($decision->decision, ['underpaid', 'verified'], true)) {
                    $received = $received->plus((string) $decision->received_amount);
                    $verifiedCount += $decision->decision === 'verified' ? 1 : 0;
                }
            }
            if ($verifiedCount !== 1 || $received->compareTo((string) $payment->amount_due) !== 0
                || DB::table('web_bank_transfer_attempts')->where('business_id', $order->business_id)
                    ->where('web_order_id', $order->id)->where('status', 'awaiting_verification')->exists()) {
                throw ValidationException::withMessages(['order' => 'Verified payment evidence is incomplete or still awaiting review.']);
            }
            if ($order->reservation_expires_at === null || WebOrder::query()->whereKey($order->id)
                ->where('reservation_expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists()) {
                throw ValidationException::withMessages(['reservation' => 'The reservation deadline has passed. Authorized review is required.']);
            }
            $this->assertCompleteReservations($order, true);
            if ($order->reservations()->where('expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists()) {
                throw ValidationException::withMessages(['reservation' => 'A reserved allocation has expired.']);
            }
            $confirmedAt = now();
            DB::table('web_order_bank_confirmations')->insert([
                'business_id' => $order->business_id, 'web_order_id' => $order->id,
                'actor_user_id' => $actor->id, 'actor_name_snapshot' => $actor->name,
                'internal_note' => $internalNote, 'confirmed_at' => $confirmedAt,
                'created_at' => $confirmedAt, 'updated_at' => $confirmedAt,
            ]);
            $order->update(['status' => 'confirmed']);
            DB::table('web_order_status_histories')->insert([
                'business_id' => $order->business_id, 'web_order_id' => $order->id,
                'from_status' => 'pending_confirmation', 'to_status' => 'confirmed',
                'actor_type' => 'staff', 'actor_id' => $actor->id,
                'reason_code' => 'bank_order_confirmed', 'created_at' => $confirmedAt,
            ]);

            return true;
        }, 3);
    }

    /** Customer-declined pending COD only; release and order transition share the outer transaction. */
    public function cancelDeclined(User $actor, string $reference): bool
    {
        abort_unless($actor->can(PermissionName::StorefrontCancelOrders->value), 403);

        return DB::transaction(function () use ($actor, $reference): bool {
            $order = $this->lockOrder($actor, $reference);
            if ($order->status === 'cancelled' && $order->payment_method === 'cod') {
                return false;
            }
            $this->assertPendingCod($order, false);
            $payment = DB::table('web_order_payments')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->lockForUpdate()->first(['method', 'status']);
            if ($payment === null || $payment->method !== 'cod' || $payment->status !== 'cod_due') {
                throw ValidationException::withMessages(['order' => 'This order requires payment reconciliation before cancellation.']);
            }
            $this->assertCompleteReservations($order, false);
            if ($this->latestOutcome($order) !== 'customer_declined') {
                throw ValidationException::withMessages(['call' => 'A customer-declined phone outcome is required before cancellation.']);
            }

            return $this->reservations->release($order, 'customer_declined', 'staff', $actor->id);
        }, 3);
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

    private function assertPendingCod(WebOrder $order, bool $checkDeadline): void
    {
        if ($order->payment_method !== 'cod' || $order->payment_status !== 'cod_due' || $order->status !== 'pending_confirmation') {
            throw ValidationException::withMessages(['order' => 'Only a pending, unpaid Cash on Delivery order can use this action.']);
        }
        if ($checkDeadline && ($order->reservation_expires_at === null
            || WebOrder::query()->whereKey($order->id)->where('reservation_expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists())) {
            throw ValidationException::withMessages(['order' => 'The reservation deadline has passed. This order cannot be confirmed.']);
        }
        if ($checkDeadline && $order->cod_confirmation_deadline_at !== null
            && WebOrder::query()->whereKey($order->id)->where('cod_confirmation_deadline_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists()) {
            throw ValidationException::withMessages(['order' => 'The COD confirmation deadline has passed. This order cannot be confirmed.']);
        }
    }

    private function latestOutcome(WebOrder $order): ?string
    {
        $value = DB::table('web_order_call_attempts')->where('business_id', $order->business_id)
            ->where('web_order_id', $order->id)->orderByDesc('id')->value('outcome');

        return is_string($value) ? $value : null;
    }

    /** Caller holds the business and order locks. */
    public function assertCompleteReservations(WebOrder $order, bool $checkDeadline, bool $lock = true): void
    {
        if ($order->web_order_offer_id === null) {
            throw ValidationException::withMessages(['reservation' => 'The accepted offer allocation is missing.']);
        }
        $offerItems = WebOrderOfferItem::query()->where('business_id', $order->business_id)
            ->where('web_order_offer_id', $order->web_order_offer_id)->get();
        $orderItems = DB::table('web_order_items')->where('business_id', $order->business_id)
            ->where('web_order_id', $order->id)->get(['id', 'source_offer_line_number']);
        if ($offerItems->isEmpty() || $offerItems->count() !== $orderItems->count()) {
            throw ValidationException::withMessages(['reservation' => 'The order allocation is incomplete.']);
        }
        $byLine = $orderItems->keyBy('source_offer_line_number');
        $expected = [];
        foreach ($offerItems as $offerItem) {
            $source = $byLine->get($offerItem->line_number);
            $allocation = $offerItem->private_allocation;
            if ($source === null || ! is_array($allocation) || $allocation === []) {
                throw ValidationException::withMessages(['reservation' => 'The order allocation is incomplete.']);
            }
            foreach ($allocation as $part) {
                if (! is_array($part) || ! isset($part['variant_id'], $part['quantity'])) {
                    throw ValidationException::withMessages(['reservation' => 'The order allocation is incomplete.']);
                }
                $devices = $part['device_ids'] ?? null;
                if (is_array($devices)) {
                    if ($devices === []) {
                        throw ValidationException::withMessages(['reservation' => 'The order allocation is incomplete.']);
                    }
                    foreach ($devices as $deviceId) {
                        $this->addExpected($expected, (int) $source->id, (int) $part['variant_id'], (int) $deviceId, '1.000');
                    }
                } else {
                    $this->addExpected($expected, (int) $source->id, (int) $part['variant_id'], null,
                        BigDecimal::of((string) $part['quantity'])->toScale(3)->__toString());
                }
            }
        }
        $query = $order->reservations()->orderBy('id');
        $reservations = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($reservations->count() !== array_sum($expected)) {
            throw ValidationException::withMessages(['reservation' => 'The order allocation is incomplete.']);
        }
        foreach ($reservations as $reservation) {
            if ($reservation->status !== 'active' || $reservation->business_id !== $order->business_id ||
                $reservation->location_id !== $order->location_id ||
                $reservation->active_device_id !== $reservation->serialized_device_id ||
                ($checkDeadline && ($reservation->expires_at === null || $reservation->expires_at->isPast()))) {
                throw ValidationException::withMessages(['reservation' => 'An order reservation is no longer active.']);
            }
            $key = $this->allocationKey($reservation->web_order_item_id, $reservation->component_variant_id,
                $reservation->serialized_device_id, (string) $reservation->quantity);
            if (($expected[$key] ?? 0) < 1) {
                throw ValidationException::withMessages(['reservation' => 'The order allocation does not match its accepted offer.']);
            }
            $expected[$key]--;
        }
    }

    /** @param array<string, int> $expected */
    private function addExpected(array &$expected, int $itemId, int $variantId, ?int $deviceId, string $quantity): void
    {
        $key = $this->allocationKey($itemId, $variantId, $deviceId, $quantity);
        $expected[$key] = ($expected[$key] ?? 0) + 1;
    }

    private function allocationKey(int $itemId, int $variantId, ?int $deviceId, string $quantity): string
    {
        return $itemId.':'.$variantId.':'.($deviceId ?? 'quantity').':'.BigDecimal::of($quantity)->toScale(3);
    }
}
