<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\WebOrder;
use App\Models\WebOrderOffer;
use App\WebFulfillmentMode;

/** First-release commercial-mode gate. Does not perform a handover. */
class WebFulfillmentPolicy
{
    public function assertAllowed(string $paymentMethod, ?string $mode): WebFulfillmentMode
    {
        $resolved = WebFulfillmentMode::tryFrom((string) $mode);
        if ($resolved === null || ! match ($resolved) {
            WebFulfillmentMode::HomeDelivery => $paymentMethod === 'bank_transfer',
            WebFulfillmentMode::ShopPickup => $paymentMethod === 'cod',
        }) {
            throw new StorefrontCartException('FULFILLMENT_MODE_UNAVAILABLE', 'This payment and fulfillment combination is unavailable.', 422);
        }

        return $resolved;
    }

    public function assertOfferMatchesOrder(WebOrderOffer $offer, WebOrder $order): void
    {
        $mode = $this->assertAllowed((string) $offer->payment_method, $offer->fulfillment_mode);
        if ($order->business_id !== $offer->business_id || $order->web_order_offer_id !== $offer->id
            || $order->payment_method !== $offer->payment_method || $order->fulfillment_mode !== $mode->value
            || $order->location_id !== $offer->location_id) {
            throw new StorefrontCartException('FULFILLMENT_MODE_UNAVAILABLE', 'The accepted fulfillment terms do not match this order.', 409);
        }
    }
}
