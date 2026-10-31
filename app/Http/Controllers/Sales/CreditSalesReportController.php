<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\CustomerAccountService;
use App\Services\LocationAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditSalesReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess, CustomerAccountService $accounts): Response
    {
        $business = $locationAccess->business();
        $filters = $request->validate([
            'product' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $locationIds = $locationAccess->accessibleQuery($request->user())->pluck('id');
        $product = trim((string) ($filters['product'] ?? ''));
        $customer = trim((string) ($filters['customer'] ?? ''));
        $locationId = isset($filters['location_id']) && $locationIds->contains((int) $filters['location_id']) ? (int) $filters['location_id'] : null;

        $applyFilters = function (Builder $query) use ($product, $customer, $filters, $locationId): Builder {
            return $query
                ->when($product !== '', fn (Builder $sale): Builder => $sale->whereHas('lines', fn (Builder $lines): Builder => $lines
                    ->where('product_name_snapshot', 'like', "%{$product}%")
                    ->orWhere('variant_name_snapshot', 'like', "%{$product}%")
                    ->orWhere('sku_snapshot', 'like', "%{$product}%")))
                ->when($customer !== '', fn (Builder $sale): Builder => $sale->where(function (Builder $scope) use ($customer): void {
                    $scope->where('customer_name', 'like', "%{$customer}%")
                        ->orWhere('customer_phone', 'like', "%{$customer}%")
                        ->orWhereHas('customer', fn (Builder $account) => $account->where('name', 'like', "%{$customer}%")->orWhere('phone', 'like', "%{$customer}%"));
                }))
                ->when(! empty($filters['date_from']), fn (Builder $sale): Builder => $sale->whereDate('posted_at', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn (Builder $sale): Builder => $sale->whereDate('posted_at', '<=', $filters['date_to']))
                ->when($locationId !== null, fn (Builder $sale): Builder => $sale->where('location_id', $locationId));
        };

        $base = $applyFilters($accounts->balances($business, $request->user())->where('is_credit', true));
        $summary = [
            'invoices' => (clone $base)->count(),
            'sales_total' => (string) (clone $base)->sum('net_total'),
            'paid_total' => (string) (clone $base)->sum('net_paid'),
            'outstanding_total' => (string) (clone $base)->sum('balance_due'),
        ];
        $sales = (clone $base)->with(['location:id,name', 'cashier:id,name', 'lines:id,sale_id,product_name_snapshot,variant_name_snapshot,sku_snapshot,quantity,total'])
            ->latest('posted_at')->latest('id')->paginate(20)->withQueryString()
            ->through(fn (Sale $sale): array => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'posted_at' => $sale->posted_at->format('Y-m-d H:i'),
                'customer' => $sale->customer_name ?: __('Walk-in customer'),
                'phone' => $sale->customer_phone,
                'location' => $sale->location->name,
                'cashier' => $sale->cashier->name,
                'total' => $sale->net_total,
                'paid' => $sale->net_paid,
                'outstanding' => $sale->balance_due,
                'payment_status' => $sale->payment_status->value,
                'lines' => $sale->lines->map(fn ($line): array => [
                    'product' => $line->product_name_snapshot,
                    'variant' => $line->variant_name_snapshot,
                    'sku' => $line->sku_snapshot,
                    'quantity' => $line->quantity,
                    'total' => $line->total,
                ]),
            ]);

        return Inertia::render('sales/CreditReport', [
            'sales' => $sales,
            'summary' => $summary,
            'locations' => $locationAccess->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'product' => $product,
                'customer' => $customer,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'location_id' => $locationId ? (string) $locationId : '',
            ],
            'currency' => $business->currency,
        ]);
    }
}
