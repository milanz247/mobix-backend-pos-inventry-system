<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\WebOrder;
use App\PermissionName;
use App\Services\LocationAccessService;
use App\Services\StorefrontOrderTrackingService;
use App\Services\WebOrderRecoveryService;
use App\Services\WebOrderStaffService;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffWebOrderController extends Controller
{
    public function index(Request $request, LocationAccessService $locations): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['pending_confirmation', 'confirmed', 'cancelled', 'expired', 'price_approval_required', 'preparing', 'ready', 'dispatched', 'delivered', 'fulfilled'])],
            'payment_method' => ['nullable', Rule::in(['cod', 'bank_transfer'])],
            'payment_status' => ['nullable', Rule::in(['cod_due', 'paid_collected', 'transfer_awaiting_proof', 'transfer_awaiting_verification', 'paid_verified', 'underpaid', 'rejected', 'refund_pending', 'refunded'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $business = $locations->business();
        $query = WebOrder::query()->where('web_orders.business_id', $business->id)
            ->whereIn('web_orders.location_id', $locations->accessibleQuery($request->user())->select('locations.id'));
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->where('web_orders.reference', 'like', $pattern)
                    ->orWhere('web_orders.contact_snapshot->name', 'like', $pattern)
                    ->orWhere('web_orders.contact_snapshot->phone', 'like', $pattern);
            });
        }
        foreach (['status', 'payment_method', 'payment_status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where('web_orders.'.$column, $filters[$column]);
            }
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('web_orders.submitted_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('web_orders.submitted_at', '<=', $filters['date_to']);
        }
        $orders = $query->orderByDesc('web_orders.submitted_at')->orderByDesc('web_orders.id')
            ->paginate(20)->withQueryString()->through(fn (WebOrder $order): array => [
                'reference' => $order->reference, 'submitted_at' => $order->submitted_at?->toIso8601String(),
                'customer_name' => $order->contact_snapshot['name'] ?? null,
                'customer_phone' => $order->contact_snapshot['phone'] ?? null,
                'location' => $order->location_name_snapshot,
                'delivery_area' => $order->delivery_area_name_snapshot,
                'currency' => $order->currency, 'total' => $order->total,
                'payment_method' => $order->payment_method, 'payment_status' => $order->payment_status,
                'status' => $order->status,
            ]);

        return Inertia::render('storefront/orders/Index', ['orders' => $orders, 'filters' => $filters]);
    }

    public function show(Request $request, string $reference, LocationAccessService $locations, StorefrontOrderTrackingService $tracking, WebOrderRecoveryService $recovery): Response
    {
        $business = $locations->business();
        $order = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference)->firstOrFail();
        $location = $locations->accessibleQuery($request->user())->findOrFail($order->location_id);
        $items = DB::table('web_order_items')->where('business_id', $business->id)->where('web_order_id', $order->id)
            ->orderBy('line_number')->get(['product_name_snapshot', 'product_slug_snapshot', 'variant_name_snapshot',
                'condition_snapshot', 'included_device_conditions_snapshot', 'quantity', 'unit_price', 'line_total'])
            ->map(fn ($item): array => [
                'name' => $item->product_name_snapshot, 'slug' => $item->product_slug_snapshot,
                'variant_name' => $item->variant_name_snapshot, 'condition' => $item->condition_snapshot,
                'included_device_conditions' => $item->included_device_conditions_snapshot === null ? null
                    : json_decode($item->included_device_conditions_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'quantity' => (string) $item->quantity, 'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->all();
        $history = DB::table('web_order_status_histories')->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();
        $calls = DB::table('web_order_call_attempts')->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->orderByDesc('id')->get([
                'outcome', 'actor_name_snapshot', 'created_at', 'internal_note',
            ]);
        $reservations = DB::table('web_order_reservations as reservation')
            ->join('product_variants as variant', 'variant.id', '=', 'reservation.component_variant_id')
            ->join('web_order_items as item', function ($join): void {
                $join->on('item.id', '=', 'reservation.web_order_item_id')
                    ->on('item.business_id', '=', 'reservation.business_id');
            })
            ->where('reservation.business_id', $business->id)->where('reservation.web_order_id', $order->id)
            ->orderBy('reservation.id')->get(['reservation.id', 'item.product_name_snapshot as item_name',
                'variant.name as component_name', 'reservation.quantity', 'reservation.status',
                'reservation.expires_at', 'reservation.serialized_device_id']);
        $checks = DB::table('web_order_fulfillment_checks')->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->orderByDesc('id')->get([
                'web_order_reservation_id', 'result', 'checked_quantity', 'internal_note', 'performed_by', 'checked_at',
            ]);
        $latestChecks = $checks->unique('web_order_reservation_id')->keyBy('web_order_reservation_id');
        $preparer = DB::table('web_order_status_histories as history')->leftJoin('users as actor', 'actor.id', '=', 'history.actor_id')
            ->where('history.business_id', $business->id)->where('history.web_order_id', $order->id)
            ->where('history.reason_code', 'preparation_started')->orderByDesc('history.id')->value('actor.name');
        $canViewProofs = $request->user()->can(PermissionName::StorefrontViewPaymentProofs->value);
        $attempts = $canViewProofs && $order->payment_method === 'bank_transfer'
            ? DB::table('web_bank_transfer_attempts')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->orderByDesc('id')->get([
                    'id', 'transfer_reference', 'submitted_amount', 'transfer_date', 'status', 'submitted_at',
                ])
            : collect();
        $decisions = $canViewProofs && $order->payment_method === 'bank_transfer'
            ? DB::table('web_bank_transfer_decisions')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->get([
                    'web_bank_transfer_attempt_id', 'decision', 'received_amount', 'actor_name_snapshot',
                    'internal_note', 'reviewed_at',
                ])->keyBy('web_bank_transfer_attempt_id')
            : collect();
        $remaining = BigDecimal::of((string) $order->total);
        foreach ($decisions as $decision) {
            if (in_array($decision->decision, ['underpaid', 'verified'], true)) {
                $remaining = $remaining->minus((string) $decision->received_amount);
            }
        }
        $bankEvidenceComplete = $canViewProofs && $remaining->isZero()
            && $decisions->contains(fn ($decision): bool => $decision->decision === 'verified')
            && ! $decisions->contains(fn ($decision): bool => $decision->decision === 'overpayment_review')
            && ! $attempts->contains(fn ($attempt): bool => $attempt->status === 'awaiting_verification');
        $issues = $recovery->issues($order);
        $bankConfirmation = $canViewProofs && $order->payment_method === 'bank_transfer'
            ? DB::table('web_order_bank_confirmations')->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->first(['actor_name_snapshot', 'internal_note', 'confirmed_at'])
            : null;

        return Inertia::render('storefront/orders/Show', [
            'order' => [
                'reference' => $order->reference, 'submitted_at' => $order->submitted_at?->toIso8601String(),
                'status' => $order->status, 'payment_method' => $order->payment_method,
                'fulfillment_mode' => $order->fulfillment_mode,
                'payment_status' => $order->payment_status, 'currency' => $order->currency,
                'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total,
                'delivery_fee' => $order->delivery_fee, 'total' => $order->total,
                'contact' => $order->contact_snapshot, 'address' => $order->address_snapshot,
                'delivery_area' => $order->delivery_area_name_snapshot,
                'pickup_location' => $order->pickup_location_name_snapshot,
                'location' => $order->location_name_snapshot ?: $location->name,
                'reservation_expires_at' => $order->reservation_expires_at?->toIso8601String(),
                'cod_confirmation_deadline_at' => $order->cod_confirmation_deadline_at?->toIso8601String(),
                'bank_proof_deadline_at' => $order->bank_proof_deadline_at?->toIso8601String(),
                'items' => $items,
            ],
            'lifecycle_issues' => $issues,
            'bank_confirmation' => $bankConfirmation,
            'public_history' => $history->map(fn ($event): array => [
                'status' => $event->to_status, 'message' => $tracking->statusMessage($event->to_status),
                'at' => $event->created_at,
            ])->all(),
            'private_history' => $history->map(fn ($event): array => [
                'from' => $event->from_status, 'to' => $event->to_status,
                'reason' => $event->reason_code, 'actor_id' => $event->actor_id,
                'at' => $event->created_at,
            ])->all(),
            'call_attempts' => $calls,
            'reservations' => $reservations->map(fn ($reservation): array => [
                'id' => $reservation->id, 'item_name' => $reservation->item_name,
                'component_name' => $reservation->component_name, 'quantity' => (string) $reservation->quantity,
                'status' => $reservation->status, 'expires_at' => $reservation->expires_at,
                'is_serialized' => $reservation->serialized_device_id !== null,
                'latest_check' => $latestChecks->get($reservation->id),
            ])->all(),
            'pick_checks' => $checks->map(fn ($check): array => [
                'reservation_id' => $check->web_order_reservation_id, 'result' => $check->result,
                'checked_quantity' => (string) $check->checked_quantity, 'internal_note' => $check->internal_note,
                'performed_by' => $check->performed_by, 'checked_at' => $check->checked_at,
            ])->all(),
            'preparer_name' => $preparer,
            'can_start_preparation' => $request->user()->can(PermissionName::StorefrontPrepareOrders->value)
                && $order->status === 'confirmed' && $issues === []
                && ($order->payment_method === 'cod' && $order->payment_status === 'cod_due'
                    || $order->payment_method === 'bank_transfer' && $order->payment_status === 'paid_verified'
                        && $bankConfirmation !== null),
            'can_verify_picks' => $request->user()->can(PermissionName::StorefrontVerifyPicks->value)
                && $order->status === 'preparing',
            'can_mark_ready' => $request->user()->can(PermissionName::StorefrontMarkReady->value)
                && $order->status === 'preparing',
            'payment_attempts' => $attempts->map(fn ($attempt): array => [
                'id' => $attempt->id, 'transfer_reference' => $attempt->transfer_reference,
                'submitted_amount' => $attempt->submitted_amount, 'transfer_date' => $attempt->transfer_date,
                'status' => $attempt->status, 'submitted_at' => $attempt->submitted_at,
                'decision' => $decisions->get($attempt->id),
            ])->all(),
            'payment_remaining' => $canViewProofs && $order->payment_method === 'bank_transfer'
                ? $remaining->toScale(2)->__toString() : null,
            'payment_verified_amount' => $canViewProofs && $order->payment_method === 'bank_transfer'
                ? BigDecimal::of((string) $order->total)->minus($remaining)->toScale(2)->__toString() : null,
            'can_view_proofs' => $canViewProofs,
            'can_verify_payments' => $canViewProofs && $request->user()->can(PermissionName::StorefrontVerifyPayments->value)
                && $order->payment_method === 'bank_transfer' && $order->payment_status === 'transfer_awaiting_verification'
                && in_array($order->status, ['pending_confirmation', 'confirmed'], true)
                && $order->reservation_expires_at?->isFuture(),
            'can_confirm' => $request->user()->can(PermissionName::StorefrontConfirmOrders->value)
                && $order->payment_method === 'cod' && $order->status === 'pending_confirmation'
                && $order->payment_status === 'cod_due' && $order->reservation_expires_at?->isFuture(),
            'can_confirm_bank' => $request->user()->can(PermissionName::StorefrontConfirmBankOrders->value)
                && $canViewProofs && $order->payment_method === 'bank_transfer'
                && $order->status === 'pending_confirmation' && $order->payment_status === 'paid_verified'
                && $order->reservation_expires_at?->isFuture() && $issues === [] && $bankEvidenceComplete,
            'can_cancel' => $request->user()->can(PermissionName::StorefrontCancelOrders->value)
                && $order->payment_method === 'cod' && $order->status === 'pending_confirmation'
                && $order->payment_status === 'cod_due' && $calls->first()?->outcome === 'customer_declined',
            'can_handover_pickup' => $request->user()->can(PermissionName::StorefrontHandoverOrders->value)
                && $request->user()->can(PermissionName::ShiftsView->value)
                && $order->status === 'ready' && $order->payment_method === 'cod'
                && $order->fulfillment_mode === 'shop_pickup' && $order->payment_status === 'cod_due'
                && $issues === [],
        ]);
    }

    public function recordCall(Request $request, string $reference, WebOrderStaffService $orders): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['answered_confirmed', 'no_answer', 'customer_declined', 'wrong_number', 'request_callback'])],
            'internal_note' => ['nullable', 'string', 'max:1000', 'required_if:outcome,customer_declined'],
        ]);
        $orders->recordCall($request->user(), $reference, $data['outcome'], $data['internal_note'] ?? null);

        return to_route('storefront-orders.show', $reference)->with('success', 'Call outcome recorded.');
    }

    public function confirm(Request $request, string $reference, WebOrderStaffService $orders): RedirectResponse
    {
        $orders->confirmCod($request->user(), $reference);

        return to_route('storefront-orders.show', $reference)->with('success', 'COD order confirmed. Payment remains due.');
    }

    public function confirmBank(Request $request, string $reference, WebOrderStaffService $orders): RedirectResponse
    {
        $data = $request->validate(['internal_note' => ['nullable', 'string', 'max:1000']]);
        $orders->confirmBank($request->user(), $reference, $data['internal_note'] ?? null);

        return to_route('storefront-orders.show', $reference)->with('success', 'Verified bank transfer order confirmed. No payment or stock was posted.');
    }

    public function cancelDeclined(Request $request, string $reference, WebOrderStaffService $orders): RedirectResponse
    {
        $orders->cancelDeclined($request->user(), $reference);

        return to_route('storefront-orders.show', $reference)->with('success', 'Declined order cancelled and reservations released.');
    }
}
