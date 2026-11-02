<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\WebOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class StorefrontOrderTrackingService
{
    /** @return array<string, mixed> */
    public function show(Business $business, string $reference, ?string $credential): array
    {
        $order = $this->authenticateOrder($business, $reference, $credential);
        $payment = $order->payment_method === 'bank_transfer'
            ? DB::table('web_order_payments')->where('business_id', $business->id)->where('web_order_id', $order->id)->first()
            : null;
        $attempts = $payment === null ? collect() : DB::table('web_bank_transfer_attempts')
            ->where('business_id', $business->id)->where('web_order_id', $order->id)
            ->orderBy('submitted_at')->orderBy('id')->get(['id', 'status', 'submitted_at']);
        $received = BigDecimal::zero();
        if ($payment !== null) {
            $amounts = DB::table('web_bank_transfer_decisions')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->whereIn('decision', ['underpaid', 'verified'])->pluck('received_amount');
            foreach ($amounts as $amount) {
                $received = $received->plus((string) $amount);
            }
        }
        $remaining = $payment === null ? null : BigDecimal::of((string) $payment->amount_due)->minus($received)->toScale(2)->__toString();
        $proofEligible = $payment !== null && in_array($order->payment_status, ['transfer_awaiting_proof', 'underpaid', 'rejected'], true)
            && in_array($order->status, ['pending_confirmation', 'confirmed'], true)
            && $order->reservation_expires_at?->isFuture()
            && DB::table('web_order_reservations')->where('business_id', $business->id)->where('web_order_id', $order->id)->where('status', 'active')->exists()
            && ! DB::table('web_order_reservations')->where('business_id', $business->id)->where('web_order_id', $order->id)->where('status', '!=', 'active')->exists();

        return $this->orderSnapshot($order, $business, $attempts->map(fn ($attempt): array => [
            'status' => (string) $attempt->status, 'submitted_at' => (string) $attempt->submitted_at,
        ])->values()->all(), $remaining, $proofEligible);
    }

    public function authenticateOrder(Business $business, string $reference, ?string $credential, bool $lock = false): WebOrder
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $reference) ||
            ! is_string($credential) || ! preg_match('/^[a-f0-9]{64}$/D', $credential)) {
            throw $this->notFound();
        }
        $query = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference);
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        $expected = $order?->guest_access_token_hash;
        if (! hash_equals(is_string($expected) && strlen($expected) === 64 ? $expected : str_repeat('0', 64), hash('sha256', $credential)) || $order === null) {
            throw $this->notFound();
        }

        return $order;
    }

    /** @param array<int, array{status: string, submitted_at: string}> $paymentAttempts
     * @return array<string, mixed>
     */
    private function orderSnapshot(WebOrder $order, Business $business, array $paymentAttempts, ?string $remaining, bool $proofEligible): array
    {

        $items = DB::table('web_order_items')->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->orderBy('line_number')->get([
                'product_slug_snapshot', 'product_name_snapshot', 'variant_name_snapshot', 'condition_snapshot',
                'included_device_conditions_snapshot', 'quantity', 'unit_price', 'line_total',
            ])->map(fn ($item): array => [
                'slug' => $item->product_slug_snapshot,
                'name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'condition' => $item->condition_snapshot,
                'included_device_conditions' => $item->included_device_conditions_snapshot === null
                    ? null : json_decode($item->included_device_conditions_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->all();
        $history = DB::table('web_order_status_histories')->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->orderBy('created_at')->orderBy('id')
            ->get(['to_status', 'created_at'])->map(fn ($event): array => [
                'status' => $this->publicStatus($event->to_status),
                'message' => $this->customerStatusMessage($order, $event->to_status),
                'at' => (string) $event->created_at,
            ])->all();
        $contact = is_array($order->contact_snapshot) ? $order->contact_snapshot : [];
        $address = is_array($order->address_snapshot) ? $order->address_snapshot : [];

        return [
            'reference' => $order->reference,
            'submitted_at' => $order->submitted_at?->toIso8601String(),
            'status' => $this->publicStatus($order->status),
            'status_message' => $order->status === 'pending_confirmation' && $order->payment_method === 'bank_transfer'
                && $order->payment_status === 'paid_verified'
                    ? 'Your bank transfer was verified. Staff order confirmation is still pending.'
                    : $this->customerStatusMessage($order, $order->status),
            'payment_method' => $order->payment_method,
            'fulfillment_mode' => $order->fulfillment_mode,
            'payment_status' => $this->publicPaymentStatus($order->payment_status),
            'payment_message' => $this->paymentMessage($order->payment_status),
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'delivery_fee' => $order->delivery_fee,
            'total' => $order->total,
            'items' => $items,
            'delivery_area' => ['code' => $order->delivery_area_code_snapshot, 'name' => $order->delivery_area_name_snapshot],
            'pickup_location' => $order->fulfillment_mode === 'shop_pickup'
                ? ['code' => $order->pickup_location_code_snapshot, 'name' => $order->pickup_location_name_snapshot,
                    'address' => $order->pickup_location_address_snapshot, 'city' => $order->pickup_location_city_snapshot] : null,
            'contact' => [
                'name' => $this->snapshot($contact, 'name'),
                'phone' => $this->snapshot($contact, 'phone'),
                'email' => $this->snapshot($contact, 'email'),
            ],
            'address' => [
                'line1' => $this->snapshot($address, 'line1'),
                'line2' => $this->snapshot($address, 'line2'),
                'city' => $this->snapshot($address, 'city'),
                'postal_code' => $this->snapshot($address, 'postal_code'),
            ],
            'history' => $history,
            'reservation_expires_at' => $order->status === 'pending_confirmation'
                ? $order->reservation_expires_at?->toIso8601String() : null,
            'payment_remaining' => $remaining,
            'proof_submission_allowed' => $proofEligible,
            'payment_attempts' => $paymentAttempts,
        ];
    }

    /** @param array<string, mixed> $source */
    private function snapshot(array $source, string $key): ?string
    {
        return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : null;
    }

    private function publicStatus(string $status): string
    {
        return array_key_exists($status, self::STATUS_MESSAGES) ? $status : 'processing';
    }

    public function statusMessage(string $status): string
    {
        return self::STATUS_MESSAGES[$status] ?? 'Contact NalakaMobile for an order update.';
    }

    private function customerStatusMessage(WebOrder $order, string $status): string
    {
        if ($order->fulfillment_mode === 'shop_pickup') {
            return match ($status) {
                'ready' => 'Your order is ready for collection at the selected shop.',
                'fulfilled' => 'Your order was collected at the shop.',
                default => $this->statusMessage($status),
            };
        }

        return $this->statusMessage($status);
    }

    private function publicPaymentStatus(?string $status): string
    {
        return $status !== null && array_key_exists($status, self::PAYMENT_MESSAGES) ? $status : 'review_required';
    }

    private function paymentMessage(?string $status): string
    {
        return $status !== null ? (self::PAYMENT_MESSAGES[$status] ?? 'Contact NalakaMobile for a payment update.')
            : 'Contact NalakaMobile for a payment update.';
    }

    private function notFound(): StorefrontCartException
    {
        return new StorefrontCartException('ORDER_NOT_FOUND', 'This order could not be accessed.', 404);
    }

    private const STATUS_MESSAGES = [
        'pending_confirmation' => 'Your order has been received. Our staff will contact you to confirm it before preparation.',
        'confirmed' => 'Your order has been confirmed.',
        'preparing' => 'Your order is being prepared.',
        'ready' => 'Your order is ready for dispatch.',
        'dispatched' => 'Your order has been dispatched.',
        'delivered' => 'Your order has been delivered.',
        'fulfilled' => 'Your order has been fulfilled.',
        'price_approval_required' => 'Updated terms require your approval before this order can continue.',
        'cancelled' => 'Your order was cancelled.',
        'expired' => 'Your order expired. Contact NalakaMobile for assistance.',
    ];

    private const PAYMENT_MESSAGES = [
        'cod_due' => 'Cash on Delivery is due. No payment has been collected yet.',
        'paid_collected' => 'Cash on Delivery payment has been recorded.',
        'transfer_awaiting_proof' => 'Your bank transfer has not been verified. Follow the instructions when they are provided.',
        'transfer_awaiting_verification' => 'Your payment information is awaiting verification by authorized staff.',
        'paid_verified' => 'Your bank transfer has been verified by authorized staff.',
        'underpaid' => 'The transfer requires further review. Contact NalakaMobile.',
        'rejected' => 'The transfer evidence was not accepted. Contact NalakaMobile.',
        'refund_pending' => 'A refund is awaiting completion.',
        'refunded' => 'A refund has been recorded.',
    ];
}
