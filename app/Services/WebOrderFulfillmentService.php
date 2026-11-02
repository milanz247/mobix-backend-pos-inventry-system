<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebOrderFulfillment;
use App\Models\WebOrderOfferItem;
use App\Models\WebOrderPayment;
use App\PermissionName;
use App\SalePaymentStatus;
use App\SaleSource;
use App\SaleStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Staff-only, one-time COD pickup posting. No bank or courier posting path exists here. */
class WebOrderFulfillmentService
{
    public function __construct(
        private readonly LocationAccessService $locations,
        private readonly CashierShiftService $shifts,
        private readonly SaleService $sales,
        private readonly WebOrderStaffService $staffOrders,
        private readonly WebOrderPreparationService $preparation,
        private readonly WebReservationService $reservations,
        private readonly InventoryService $inventory,
        private readonly WebPickupLocationService $pickupLocations,
        private readonly WebFulfillmentPolicy $fulfillmentPolicy,
    ) {}

    /** @return array{sale: Sale, fulfillment: WebOrderFulfillment, replayed: bool} */
    public function collectPickup(User $actor, string $reference, string $operationKey, string $cashTendered): array
    {
        abort_unless($actor->can(PermissionName::StorefrontHandoverOrders->value)
            && $actor->can(PermissionName::ShiftsView->value), 403);
        Validator::make(['operation_key' => $operationKey, 'cash_tendered' => $cashTendered], [
            'operation_key' => ['required', 'uuid'],
            'cash_tendered' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ])->validate();
        $tender = BigDecimal::of($cashTendered)->toScale(2, RoundingMode::Unnecessary)->__toString();
        if (! BigDecimal::of($tender)->isPositive()) {
            throw ValidationException::withMessages(['cash_tendered' => 'Enter the actual cash received at pickup.']);
        }

        return DB::transaction(function () use ($actor, $reference, $operationKey, $tender): array {
            $business = $this->locations->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $order = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference)
                ->lockForUpdate()->firstOrFail();
            $location = Location::query()->where('business_id', $business->id)->whereKey($order->location_id)
                ->firstOrFail();
            abort_unless($this->locations->canAccess($actor, $location), 404);
            $hash = hash('sha256', json_encode([
                'business' => $business->id, 'order' => $order->id, 'actor' => $actor->id,
                'mode' => 'shop_pickup', 'cash_tendered' => $tender,
            ], JSON_THROW_ON_ERROR));
            $existing = WebOrderFulfillment::query()->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->operation_key !== $operationKey || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This order has already been handed over with different submission terms.');
                }
                $sale = Sale::query()->where('business_id', $business->id)->whereKey($existing->sale_id)
                    ->where('web_order_id', $order->id)->lockForUpdate()->firstOrFail();

                return ['sale' => $sale, 'fulfillment' => $existing, 'replayed' => true];
            }
            if (Sale::query()->where('business_id', $business->id)->where('web_order_id', $order->id)->exists()
                || WebOrderFulfillment::query()->where('business_id', $business->id)
                    ->where('operation_key', $operationKey)->exists()) {
                throw new ConflictHttpException('This handover requires reconciliation before retry.');
            }
            if ($order->payment_method === 'bank_transfer' || $order->fulfillment_mode === 'home_delivery') {
                $this->fail('Bank receipt attribution and delivery-fee accounting approval are pending; home-delivery handover is unavailable.');
            }
            if ($order->status !== 'ready' || $order->payment_method !== 'cod' ||
                $order->fulfillment_mode !== 'shop_pickup' || $order->payment_status !== 'cod_due') {
                $this->fail('Only a ready, unpaid COD shop-pickup order can be collected.');
            }
            if ($location->status->value !== 'active') {
                $this->fail('The accepted pickup location is not active.');
            }
            $shiftId = $this->shifts->activeId($actor, $location->id);
            if ($shiftId === null) {
                throw ValidationException::withMessages(['shift' => 'Open your cashier shift at this pickup shop before collecting cash.']);
            }
            $location = Location::query()->where('business_id', $business->id)->whereKey($order->location_id)
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->fulfillmentPolicy->assertAllowed($order->payment_method, $order->fulfillment_mode);
            if ($location->type->value !== 'shop' || $location->code !== $order->location_code_snapshot ||
                $location->name !== $order->location_name_snapshot ||
                BigDecimal::of((string) $order->delivery_fee)->compareTo('0.00') !== 0 ||
                $order->delivery_area_code_snapshot !== null || $order->pickup_location_code_snapshot === null) {
                $this->fail('The accepted pickup location or zero-fee terms are inconsistent.');
            }
            $pickup = $this->pickupLocations->resolve($business, $order->pickup_location_code_snapshot, true);
            $snapshot = $pickup === null ? null : $this->pickupLocations->publicSnapshot($pickup);
            if ($pickup === null || $pickup->location_id !== $location->id ||
                $snapshot['code'] !== $order->pickup_location_code_snapshot ||
                $snapshot['name'] !== $order->pickup_location_name_snapshot ||
                $snapshot['address'] !== $order->pickup_location_address_snapshot ||
                $snapshot['city'] !== $order->pickup_location_city_snapshot) {
                $this->fail('The selected pickup shop changed. An authorized review is required.');
            }
            $payment = WebOrderPayment::query()->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->lockForUpdate()->first();
            if ($payment === null || $payment->method !== 'cod' || $payment->status !== 'cod_due' ||
                $payment->currency !== $order->currency ||
                BigDecimal::of((string) $payment->amount_due)->compareTo((string) $order->total) !== 0 ||
                BigDecimal::of((string) $order->discount_total)->compareTo('0.00') !== 0 ||
                BigDecimal::of((string) $order->subtotal)->compareTo((string) $order->total) !== 0 ||
                DB::table('sale_payments')->where('business_id', $business->id)
                    ->where('web_cod_order_id', $order->id)->exists()) {
                $this->fail('The accepted COD obligation requires reconciliation.');
            }
            $this->assertMoneyFitsSale((string) $order->total);
            $cash = $this->sales->paymentCashDetails('cash', (string) $order->total, $tender);
            $this->preparation->assertReadyForHandover($order);
            $this->staffOrders->assertCompleteReservations($order, true);
            $items = DB::table('web_order_items')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->orderBy('line_number')->lockForUpdate()->get();
            if ($items->isEmpty() || $order->product_tax_total_snapshot === null || $order->product_tax_rate_snapshot === null) {
                $this->fail('Accepted product or tax snapshots are missing. This order requires manual review.');
            }
            $allocations = $order->reservations()->orderBy('location_id')->orderBy('component_variant_id')
                ->orderBy('id')->lockForUpdate()->get();
            if (DB::table('web_order_reservations')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'));
                })->exists()) {
                $this->fail('A reservation deadline has passed. An authorized review is required.');
            }
            $byItem = $allocations->groupBy('web_order_item_id');
            $acceptedItems = WebOrderOfferItem::query()->where('business_id', $business->id)
                ->where('web_order_offer_id', $order->web_order_offer_id)->orderBy('line_number')
                ->lockForUpdate()->get()->keyBy('line_number');
            $subtotal = BigDecimal::zero();
            $tax = BigDecimal::zero();
            foreach ($items as $item) {
                $accepted = $acceptedItems->get($item->source_offer_line_number);
                if ($accepted === null || $accepted->product_id !== $item->product_id ||
                    $accepted->product_variant_id !== $item->product_variant_id ||
                    $accepted->product_name !== $item->product_name_snapshot ||
                    $accepted->variant_name !== $item->variant_name_snapshot ||
                    $accepted->condition_snapshot !== $item->condition_snapshot ||
                    BigDecimal::of((string) $accepted->quantity)->compareTo((string) $item->quantity) !== 0 ||
                    BigDecimal::of((string) $accepted->unit_price)->compareTo((string) $item->unit_price) !== 0 ||
                    BigDecimal::of((string) $accepted->line_total)->compareTo((string) $item->line_total) !== 0) {
                    $this->fail('The order line differs from its accepted offer snapshot.');
                }
                if ($item->sku_snapshot === null || $item->tracking_mode_snapshot === null ||
                    $item->private_inventory_snapshot === null ||
                    $item->is_combo_snapshot === null || $item->tax_total_snapshot === null ||
                    $item->currency !== $order->currency || ! $byItem->has($item->id) ||
                    BigDecimal::of((string) $item->unit_price)->multipliedBy((string) $item->quantity)
                        ->toScale(2, RoundingMode::HalfUp)->compareTo((string) $item->line_total) !== 0 ||
                    BigDecimal::of((string) $item->line_total)
                        ->multipliedBy((string) $order->product_tax_rate_snapshot)
                        ->dividedBy(BigDecimal::of('100')->plus((string) $order->product_tax_rate_snapshot), 2, RoundingMode::HalfUp)
                        ->compareTo((string) $item->tax_total_snapshot) !== 0) {
                    $this->fail('An accepted order line snapshot is incomplete or inconsistent.');
                }
                $this->assertMoneyFitsSale((string) $item->line_total);
                $subtotal = $subtotal->plus((string) $item->line_total);
                $tax = $tax->plus((string) $item->tax_total_snapshot);
            }
            if ($subtotal->compareTo((string) $order->subtotal) !== 0 ||
                $tax->compareTo((string) $order->product_tax_total_snapshot) !== 0) {
                $this->fail('The accepted total or included product tax does not reconcile.');
            }

            $contact = is_array($order->contact_snapshot) ? $order->contact_snapshot : [];
            $sale = Sale::query()->create([
                'business_id' => $business->id, 'location_id' => $location->id,
                'web_order_id' => $order->id, 'operation_key' => $operationKey, 'payload_hash' => $hash,
                'cashier_id' => $actor->id, 'cashier_shift_id' => $shiftId, 'source' => SaleSource::Ecommerce,
                'is_credit' => false, 'customer_name' => (string) ($contact['name'] ?? ''),
                'customer_phone' => $contact['phone'] ?? null, 'customer_email' => $contact['email'] ?? null,
                'subtotal' => $order->subtotal, 'discount_total' => '0.00',
                'tax_total' => $order->product_tax_total_snapshot, 'total' => $order->total,
                'paid_total' => $order->total, 'status' => SaleStatus::Completed,
                'payment_status' => SalePaymentStatus::Paid, 'posted_at' => now(),
            ]);
            $sale->update(['sale_number' => sprintf('SAL-%s-%07d', now()->format('Ymd'), $sale->id)]);
            $saleLines = [];
            foreach ($items as $item) {
                $variant = ProductVariant::query()->where('business_id', $business->id)
                    ->whereKey($item->product_variant_id)->with('product')->firstOrFail();
                if ($variant->product_id !== $item->product_id ||
                    $variant->tracking_mode->value !== $item->tracking_mode_snapshot ||
                    ($variant->product->product_type->value === 'combo') !== (bool) $item->is_combo_snapshot) {
                    $this->fail('An accepted product identity or tracking mode changed.');
                }
                $itemAllocation = $byItem->get($item->id);
                $directDevice = ! $item->is_combo_snapshot && $itemAllocation->count() === 1
                    ? $itemAllocation->first()->serialized_device_id : null;
                $device = $directDevice === null ? null : SerializedDevice::query()
                    ->where('business_id', $business->id)->where('location_id', $location->id)
                    ->lockForUpdate()->findOrFail($directDevice);
                $saleLines[$item->id] = $sale->lines()->create([
                    'business_id' => $business->id, 'product_variant_id' => $item->product_variant_id,
                    'serialized_device_id' => $device?->id, 'product_name_snapshot' => $item->product_name_snapshot,
                    'variant_name_snapshot' => $item->variant_name_snapshot, 'sku_snapshot' => $item->sku_snapshot,
                    'barcode_snapshot' => $item->barcode_snapshot, 'tracking_mode' => $item->tracking_mode_snapshot,
                    'is_combo' => (bool) $item->is_combo_snapshot, 'is_service' => false, 'imei_snapshot' => $device?->imei,
                    'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
                    'discount_amount' => '0.00', 'cost_snapshot' => '0.00',
                    'subtotal' => $item->line_total, 'total' => $item->line_total,
                    'tax_total' => $item->tax_total_snapshot,
                ]);
            }
            $sale->payments()->create([
                'business_id' => $business->id, 'web_cod_order_id' => $order->id,
                'cashier_shift_id' => $shiftId, 'operation_key' => $operationKey, 'payload_hash' => $hash,
                'method' => 'cash', 'amount' => $order->total, 'cash_tendered' => $cash['tendered'],
                'change_given' => $cash['change'], 'reference' => null,
                'paid_at' => now(), 'received_by' => $actor->id,
            ]);
            $operation = null;
            $this->reservations->consumeReadyForHandover($order,
                function (WebOrder $locked, $consumed) use ($actor, $sale, $saleLines, &$operation): void {
                    $operation = $this->inventory->postExactWebSale($actor, $sale, $consumed, $saleLines);
                }, $actor->id);
            if ($operation === null) {
                throw new \LogicException('The exact inventory operation was not posted.');
            }
            $fulfillment = WebOrderFulfillment::query()->create([
                'business_id' => $business->id, 'web_order_id' => $order->id, 'sale_id' => $sale->id,
                'inventory_operation_id' => $operation->id, 'handover_mode' => 'shop_pickup',
                'handover_status' => 'posted', 'operation_key' => $operationKey, 'payload_hash' => $hash,
                'handover_actor_id' => $actor->id, 'handover_at' => now(),
            ]);
            $payment->update(['status' => 'paid_collected']);
            $order->update(['status' => 'fulfilled', 'payment_status' => 'paid_collected']);
            DB::table('web_order_status_histories')->insert([
                'business_id' => $business->id, 'web_order_id' => $order->id,
                'from_status' => 'ready', 'to_status' => 'fulfilled', 'actor_type' => 'staff',
                'actor_id' => $actor->id, 'reason_code' => 'shop_pickup_collected', 'created_at' => now(),
            ]);

            return ['sale' => $sale, 'fulfillment' => $fulfillment, 'replayed' => false];
        }, 3);
    }

    private function assertMoneyFitsSale(string $amount): void
    {
        if (BigDecimal::of($amount)->isGreaterThan('999999999999.99')) {
            $this->fail('The accepted amount cannot be represented by the POS sale ledger.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['handover' => $message]);
    }
}
