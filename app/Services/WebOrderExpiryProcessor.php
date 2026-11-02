<?php

namespace App\Services;

use App\Models\Business;
use App\Models\WebOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebOrderExpiryProcessor
{
    public function __construct(
        private readonly WebReservationService $reservations,
        private readonly WebOrderStaffService $staffOrders,
        private readonly WebOrderRecoveryService $recovery,
    ) {}

    /** @return array{outcome: 'expired'|'manual_review'|'skipped', reservations_released: int} */
    public function process(int $businessId, int $orderId): array
    {
        return DB::transaction(function () use ($businessId, $orderId): array {
            Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $order = WebOrder::query()->where('business_id', $businessId)->lockForUpdate()->findOrFail($orderId);
            $issues = $this->recovery->issues($order);
            if ($order->status !== 'pending_confirmation') {
                return ['outcome' => $issues === [] ? 'skipped' : 'manual_review', 'reservations_released' => 0];
            }
            if ($issues !== [] && array_diff($issues, ['expired_reservation_pending_order']) !== []) {
                return ['outcome' => 'manual_review', 'reservations_released' => 0];
            }
            try {
                $this->staffOrders->assertCompleteReservations($order, false);
            } catch (ValidationException) {
                return ['outcome' => 'manual_review', 'reservations_released' => 0];
            }
            $count = $order->reservations()->where('status', 'active')->count();
            if (! $this->reservations->expire($order)) {
                return ['outcome' => 'skipped', 'reservations_released' => 0];
            }

            return ['outcome' => 'expired', 'reservations_released' => $count];
        }, 3);
    }
}
