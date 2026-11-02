<?php

namespace App\Services;

use App\Models\WebOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only lifecycle diagnostics. Nothing here repairs or releases stock. */
class WebOrderRecoveryService
{
    public function __construct(private readonly WebOrderStaffService $staffOrders) {}

    /** @return list<string> */
    public function issues(WebOrder $order): array
    {
        $issues = [];
        $reservations = $order->reservations()->orderBy('id')->get();
        $active = $reservations->where('status', 'active');
        if ($reservations->isEmpty() && in_array($order->status, ['pending_confirmation', 'confirmed', 'preparing', 'ready'], true)) {
            $issues[] = 'missing_reservations';
        }
        if ($active->isNotEmpty() && in_array($order->status, ['cancelled', 'expired', 'fulfilled'], true)) {
            $issues[] = 'terminal_order_has_active_reservations';
        }
        if ($active->isNotEmpty() && ! in_array($order->status, ['pending_confirmation', 'confirmed', 'preparing', 'ready', 'cancelled', 'expired', 'fulfilled'], true)) {
            $issues[] = 'active_reservation_without_eligible_order';
        }
        if ($active->isNotEmpty() && $active->count() !== $reservations->count()) {
            $issues[] = 'partially_transitioned_reservations';
        }
        if (in_array($order->status, ['confirmed', 'preparing', 'ready'], true) && $reservations->contains('status', 'expired')) {
            $issues[] = $order->status === 'confirmed' ? 'confirmed_order_has_expired_reservation' : 'preparation_has_expired_reservation';
        }
        if ($order->status === 'pending_confirmation' && $active->contains(fn ($row): bool => $row->expires_at?->isPast() === true)) {
            $issues[] = 'expired_reservation_pending_order';
        }
        if ($order->status === 'pending_confirmation' && $order->reservation_expires_at?->isPast()
            && ($order->payment_method === 'cod' && $order->cod_confirmation_deadline_at === null
                || $order->payment_method === 'bank_transfer' && $order->bank_proof_deadline_at === null)) {
            $issues[] = 'expiry_policy_missing';
        }
        if ($order->payment_method === 'bank_transfer' && $order->status === 'pending_confirmation'
            && $order->payment_status !== 'paid_verified'
            && ($order->bank_proof_deadline_at?->isPast() || $order->reservation_expires_at?->isPast())
            && ($order->payment_status !== 'transfer_awaiting_proof'
                || DB::table('web_bank_transfer_attempts')->where('business_id', $order->business_id)
                    ->where('web_order_id', $order->id)->exists())) {
            $issues[] = 'bank_payment_reconciliation_required';
        }
        if ($order->status === 'pending_confirmation' && $order->payment_method === 'cod'
            && $order->cod_confirmation_deadline_at?->isPast() && $order->payment_status !== 'cod_due') {
            $issues[] = 'cod_payment_reconciliation_required';
        }
        if ($order->payment_method === 'bank_transfer' && in_array($order->status, ['confirmed', 'preparing', 'ready'], true)
            && ! DB::table('web_order_bank_confirmations')->where('business_id', $order->business_id)
                ->where('web_order_id', $order->id)->exists()) {
            $issues[] = 'bank_order_confirmation_missing';
        }
        if (in_array($order->status, ['pending_confirmation', 'confirmed', 'preparing', 'ready'], true) && $reservations->isNotEmpty()) {
            try {
                $this->staffOrders->assertCompleteReservations($order, false, false);
            } catch (ValidationException) {
                $issues[] = 'reservation_allocation_mismatch';
            }
        }
        foreach ($active->whereNotNull('active_device_id') as $reservation) {
            if (DB::table('web_order_reservations')->where('active_device_id', $reservation->active_device_id)
                ->where('status', 'active')->count() > 1) {
                $issues[] = 'duplicate_active_device_allocation';

                break;
            }
        }

        return array_values(array_unique($issues));
    }

    public function orphanReservationCount(): int
    {
        return DB::table('web_order_reservations as reservation')
            ->leftJoin('web_orders as orders', function ($join): void {
                $join->on('orders.id', '=', 'reservation.web_order_id')
                    ->on('orders.business_id', '=', 'reservation.business_id');
            })->whereNull('orders.id')->count();
    }
}
