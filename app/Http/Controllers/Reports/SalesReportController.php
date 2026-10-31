<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\LocationAccessService;
use App\Services\SalesActivityService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    public function daily(Request $request, LocationAccessService $access, SalesActivityService $activity): Response
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'location_id' => ['nullable', 'integer'], 'cashier_id' => ['nullable', 'integer'], 'section' => ['nullable', Rule::in(['sales', 'items', 'payments', 'returns', 'refunds'])]]);
        $business = $access->business();
        $user = $request->user();
        $date = $filters['date'] ?? now($business->timezone)->toDateString();
        $start = Carbon::parse($date, $business->timezone)->startOfDay();
        $end = $start->copy()->addDay();
        $locationId = $request->integer('location_id') ?: null;
        if ($locationId) {
            $access->accessibleQuery($user)->findOrFail($locationId);
        }
        $saleIds = $business->sales()->whereIn('location_id', $access->accessibleQuery($user)->select('locations.id'))->when($locationId, fn ($q) => $q->where('location_id', $locationId))->select('id');
        $sales = $activity->query($user, $business, $locationId)->where('posted_at', '>=', $start)->where('posted_at', '<', $end)
            ->when($request->integer('cashier_id'), fn ($q) => $q->where('cashier_id', $request->integer('cashier_id')));
        $lines = $activity->lines($user, $business, $locationId)->join('sales as invoice', 'invoice.id', '=', 'activity_lines.sale_id')->leftJoin('users as item_actor', 'item_actor.id', '=', 'activity_lines.event_actor_id')->leftJoin('locations as item_branch', 'item_branch.id', '=', 'activity_lines.location_id')
            ->where('event_at', '>=', $start)->where('event_at', '<', $end)
            ->when($request->integer('cashier_id'), fn ($q) => $q->where('activity_lines.event_actor_id', $request->integer('cashier_id')));
        $payments = SalePayment::query()->where('business_id', $business->id)->whereIn('sale_id', clone $saleIds)->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->when($request->integer('cashier_id'), fn ($q) => $q->where('received_by', $request->integer('cashier_id')));
        $returns = SaleReturn::query()->where('business_id', $business->id)->whereIn('sale_id', clone $saleIds)->where('processed_at', '>=', $start)->where('processed_at', '<', $end)->when($request->integer('cashier_id'), fn ($q) => $q->where('processed_by', $request->integer('cashier_id')));
        $refunds = SaleRefund::query()->where('business_id', $business->id)->whereIn('sale_id', clone $saleIds)->where('refunded_at', '>=', $start)->where('refunded_at', '<', $end)->when($request->integer('cashier_id'), fn ($q) => $q->where('processed_by', $request->integer('cashier_id')));
        $gross = (string) (clone $sales)->sum('total');
        $returned = (string) (clone $returns)->sum('total');
        $paid = (string) (clone $payments)->sum('amount');
        $refunded = (string) (clone $refunds)->sum('amount');
        $section = $filters['section'] ?? 'sales';
        if ($section === 'sales') {
            $rows = (clone $sales)->leftJoin('users as actor', 'actor.id', '=', 'sales_activity.cashier_id')->leftJoin('locations as branch', 'branch.id', '=', 'sales_activity.location_id')->select('sales_activity.*', 'actor.name as staff', 'branch.name as branch')
                ->orderByDesc('posted_at')->orderByDesc('sale_id')->orderBy('event_type')->orderByDesc('event_id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->event_type.'-'.$row->event_id, 'reference' => $row->sale_number, 'at' => Carbon::parse($row->posted_at, $business->timezone)->toIso8601String(), 'staff' => $row->staff, 'branch' => $row->branch, 'description' => $row->customer_name, 'type' => $row->event_type, 'quantity' => null, 'amount' => $row->total, 'sale_id' => $row->sale_id]);
        } elseif ($section === 'items') {
            $rows = $lines->select('activity_lines.*', 'invoice.sale_number', 'item_actor.name as staff', 'item_branch.name as branch')->orderByDesc('event_at')->orderByDesc('activity_lines.id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'reference' => $row->sale_number, 'at' => Carbon::parse($row->event_at, $business->timezone)->toIso8601String(), 'staff' => $row->staff, 'branch' => $row->branch, 'description' => $row->product_name_snapshot.' · '.$row->variant_name_snapshot.' · '.$row->sku_snapshot.($row->imei_snapshot ? ' · IMEI '.$row->imei_snapshot : ''), 'type' => $row->sale_return_id ? 'replacement' : 'sold item', 'quantity' => $row->quantity, 'amount' => $row->total, 'sale_id' => $row->sale_id]);
        } elseif ($section === 'payments') {
            $rows = $payments->with(['sale.location', 'receiver'])->latest('paid_at')->latest('id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'reference' => $row->sale->sale_number, 'at' => $row->paid_at->toIso8601String(), 'staff' => $row->receiver->name, 'branch' => $row->sale->location->name, 'description' => $row->reference, 'type' => $row->method, 'quantity' => null, 'amount' => $row->amount, 'sale_id' => $row->sale_id]);
        } elseif ($section === 'returns') {
            $rows = $returns->with(['sale.location', 'processor'])->latest('processed_at')->latest('id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'reference' => $row->return_number, 'at' => $row->processed_at->toIso8601String(), 'staff' => $row->processor->name, 'branch' => $row->sale->location->name, 'description' => $row->reason, 'type' => $row->is_exchange ? 'replacement return' : 'return', 'quantity' => null, 'amount' => $row->total, 'sale_id' => $row->sale_id]);
        } else {
            $rows = $refunds->with(['sale.location', 'processor'])->latest('refunded_at')->latest('id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'reference' => $row->sale->sale_number, 'at' => $row->refunded_at->toIso8601String(), 'staff' => $row->processor->name, 'branch' => $row->sale->location->name, 'description' => $row->reference, 'type' => $row->method, 'quantity' => null, 'amount' => $row->amount, 'sale_id' => $row->sale_id]);
        }

        return Inertia::render('reports/DailySales', [
            'rows' => $rows, 'summary' => ['invoices' => (int) (clone $sales)->sum('initial_sale'), 'gross_sales' => $gross, 'returns' => $returned, 'net_sales' => (string) BigDecimal::of($gross)->minus($returned)->toScale(2), 'collections' => $paid, 'refunds' => $refunded, 'net_collections' => (string) BigDecimal::of($paid)->minus($refunded)->toScale(2)],
            'section' => $section, 'filters' => [...$filters, 'date' => $date], 'currency' => $business->currency, 'timezone' => $business->timezone,
            'locations' => $access->accessibleQuery($user)->orderBy('name')->get(['id', 'name']),
            'cashiers' => User::query()->whereIn('id', $business->sales()->whereIn('id', clone $saleIds)->select('cashier_id')->union(SalePayment::query()->whereIn('sale_id', clone $saleIds)->select('received_by'))->union(SaleReturn::query()->whereIn('sale_id', clone $saleIds)->select('processed_by'))->union(SaleRefund::query()->whereIn('sale_id', clone $saleIds)->select('processed_by')))->orderBy('name')->get(['id', 'name']),
            'canViewSales' => $user->can('sales.view'),
        ]);
    }

    public function all(Request $request, LocationAccessService $access, CustomerAccountService $accounts): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'location_id' => ['nullable', 'integer'], 'cashier_id' => ['nullable', 'integer'], 'payment_status' => ['nullable', Rule::in(['paid', 'unpaid', 'partially_paid'])]]);
        $business = $access->business();
        $user = $request->user();
        $query = $accounts->balances($business)->whereIn('location_id', $access->accessibleQuery($user)->select('locations.id'));
        foreach (['location_id', 'cashier_id', 'payment_status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('posted_at', '>=', Carbon::parse($filters['date_from'], $business->timezone)->startOfDay());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('posted_at', '<', Carbon::parse($filters['date_to'], $business->timezone)->addDay()->startOfDay());
        }
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('sale_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")->orWhere('customer_phone', 'like', "%{$search}%"));
        }
        $summary = ['invoices' => (clone $query)->count(), 'net_sales' => (string) (clone $query)->sum('net_total'), 'net_paid' => (string) (clone $query)->sum('net_paid'), 'outstanding' => (string) (clone $query)->sum('balance_due')];

        return Inertia::render('reports/AllSales', ['rows' => $query->with(['cashier:id,name', 'location:id,name'])->latest('posted_at')->latest('id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'invoice' => $row->sale_number, 'date' => $row->posted_at->toIso8601String(), 'customer' => $row->customer_name, 'cashier' => $row->cashier->name, 'branch' => $row->location->name, 'credit' => $row->is_credit, 'net_total' => $row->getAttribute('net_total'), 'net_paid' => $row->getAttribute('net_paid'), 'balance' => $row->getAttribute('balance_due'), 'status' => $row->payment_status->value]),
            'summary' => $summary, 'filters' => $filters, 'currency' => $business->currency, 'timezone' => $business->timezone, 'canViewSales' => $user->can('sales.view'),
            'locations' => $access->accessibleQuery($user)->orderBy('name')->get(['id', 'name']), 'cashiers' => User::query()->whereIn('id', $business->sales()->whereIn('location_id', $access->accessibleQuery($user)->select('locations.id'))->select('cashier_id'))->orderBy('name')->get(['id', 'name'])]);
    }
}
