<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\User;
use App\SalePaymentStatus;
use App\SaleStatus;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use App\Support\SalePageData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function reports(Request $request, LocationAccessService $locationAccess): Response
    {
        $business = $locationAccess->business();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $dateFrom = $filters['date_from'] ?? now()->subDays(29)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $locations = $locationAccess->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']);
        $locationIds = $locationAccess->accessibleQuery($request->user())->select('locations.id');
        $sales = $business->sales()->whereIn('location_id', $locationIds)
            ->whereDate('posted_at', '>=', $dateFrom)->whereDate('posted_at', '<=', $dateTo)
            ->when(! empty($filters['location_id']), fn (Builder $query): Builder => $query->where('location_id', (int) $filters['location_id']));
        $saleIds = (clone $sales)->select('id');
        $grossSales = (string) (clone $sales)->sum('total');
        $returnsTotal = (string) SaleReturn::query()->where('business_id', $business->id)->whereIn('sale_id', $saleIds)->sum('total');
        $paymentsTotal = (string) SalePayment::query()->where('business_id', $business->id)->whereIn('sale_id', $saleIds)->sum('amount');
        $refundsTotal = (string) SaleRefund::query()->where('business_id', $business->id)->whereIn('sale_id', $saleIds)->sum('amount');
        $netSales = BigDecimal::of($grossSales)->minus($returnsTotal)->toScale(2, RoundingMode::HalfUp);
        $netReceived = BigDecimal::of($paymentsTotal)->minus($refundsTotal);
        $outstanding = BigDecimal::max($netSales->minus($netReceived), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
        $daily = (clone $sales)->selectRaw('DATE(posted_at) as day, COUNT(*) as sales_count, SUM(total) as gross_total')
            ->groupByRaw('DATE(posted_at)')->orderBy('day')->get()
            ->map(fn (Sale $row): array => ['day' => (string) $row->getAttribute('day'), 'sales_count' => (int) $row->getAttribute('sales_count'), 'gross_total' => (string) $row->getAttribute('gross_total')]);
        $paymentMethods = SalePayment::query()->where('business_id', $business->id)->whereIn('sale_id', $saleIds)
            ->selectRaw('method, SUM(amount) as amount, SUM(change_given) as change_given')
            ->groupBy('method')->orderBy('method')->get()
            ->map(fn (object $row): array => ['method' => $row->method, 'amount' => (string) $row->amount, 'change_given' => (string) $row->change_given]);
        $topProducts = SaleLine::query()->whereIn('sale_id', $saleIds)
            ->selectRaw('product_name_snapshot, variant_name_snapshot, sku_snapshot, SUM(quantity) as quantity_sold, SUM(total) as gross_total')
            ->groupBy('product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot')
            ->orderByDesc('quantity_sold')->limit(10)->get()
            ->map(fn (SaleLine $row): array => ['product_name' => (string) $row->getAttribute('product_name_snapshot'), 'variant_name' => (string) $row->getAttribute('variant_name_snapshot'), 'sku' => (string) $row->getAttribute('sku_snapshot'), 'quantity_sold' => (string) $row->getAttribute('quantity_sold'), 'gross_total' => (string) $row->getAttribute('gross_total')]);

        return Inertia::render('sales/Reports', [
            'summary' => [
                'sales_count' => (int) (clone $sales)->count(),
                'gross_sales' => BigDecimal::of($grossSales)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'returns' => BigDecimal::of($returnsTotal)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'net_sales' => $netSales->__toString(),
                'payments_received' => BigDecimal::of($paymentsTotal)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'refunds' => BigDecimal::of($refundsTotal)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'outstanding' => $outstanding->__toString(),
            ],
            'daily' => $daily,
            'paymentMethods' => $paymentMethods,
            'topProducts' => $topProducts,
            'locations' => $locations,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'location_id' => $filters['location_id'] ?? ''],
            'currency' => $business->currency,
        ]);
    }

    public function index(Request $request, LocationAccessService $locationAccess): Response
    {
        $business = $locationAccess->business();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $paymentStatus = SalePaymentStatus::tryFrom((string) ($filters['payment_status'] ?? ''));
        $status = SaleStatus::tryFrom((string) ($filters['status'] ?? ''));
        $accessibleLocationIds = $locationAccess->accessibleQuery($request->user())->select('locations.id');
        $sales = $business->sales()->whereIn('location_id', $accessibleLocationIds)
            ->with(['location:id,name,code', 'cashier:id,name'])->withCount('lines')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('sale_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('lines', fn (Builder $lines): Builder => $lines->where('sku_snapshot', 'like', "%{$search}%")
                        ->orWhere('barcode_snapshot', 'like', "%{$search}%")
                        ->orWhere('imei_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('components', fn (Builder $components): Builder => $components->where('imei_snapshot', 'like', "%{$search}%")));
            }))
            ->when(! empty($filters['date_from']), fn (Builder $query): Builder => $query->whereDate('posted_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $query): Builder => $query->whereDate('posted_at', '<=', $filters['date_to']))
            ->when(! empty($filters['location_id']), fn (Builder $query): Builder => $query->where('location_id', (int) $filters['location_id']))
            ->when(! empty($filters['cashier_id']), fn (Builder $query): Builder => $query->where('cashier_id', (int) $filters['cashier_id']))
            ->when($paymentStatus !== null, fn (Builder $query): Builder => $query->where('payment_status', $paymentStatus->value))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->latest('posted_at')->latest('id')->paginate(15)->withQueryString()->through(fn (Sale $sale): array => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'source' => $sale->source->label(),
                'posted_at' => $sale->posted_at->format('Y-m-d H:i'),
                'customer' => $sale->customer_name ?: __('Walk-in customer'),
                'cashier' => $sale->cashier->name,
                'location' => $sale->location->name,
                'lines_count' => $sale->lines_count,
                'total' => $sale->total,
                'payment_status' => $sale->payment_status->value,
                'status' => $sale->status->value,
            ]);

        $cashierIds = $business->sales()->whereIn('location_id', $accessibleLocationIds)->select('cashier_id')->distinct();

        return Inertia::render('sales/Index', [
            'sales' => $sales,
            'locations' => $locationAccess->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']),
            'cashiers' => User::query()->whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'location_id' => $filters['location_id'] ?? '',
                'cashier_id' => $filters['cashier_id'] ?? '',
                'payment_status' => $paymentStatus !== null ? $paymentStatus->value : '',
                'status' => $status !== null ? $status->value : '',
            ],
            'paymentStatuses' => array_map(fn (SalePaymentStatus $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], SalePaymentStatus::cases()),
            'statuses' => array_map(fn (SaleStatus $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], SaleStatus::cases()),
            'currency' => $business->currency,
        ]);
    }

    public function show(Sale $sale, SaleService $sales, SalePageData $pageData): Response
    {
        $sale = $sales->scoped(request()->user(), $sale);

        return Inertia::render('sales/Show', [
            'sale' => $pageData->sale($sale, request()->user()->can('sales.costs.view')),
            'currency' => $sale->business->currency,
            'outstanding' => $sales->outstanding($sale)->__toString(),
            'canRecordPayment' => request()->user()->can('sale-payments.create'),
            'canReturn' => request()->user()->can('sale-returns.create'),
            'canRefund' => request()->user()->can('sale-refunds.create'),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function receipt(Sale $sale, SaleService $sales, SalePageData $pageData): Response
    {
        $sale = $sales->scoped(request()->user(), $sale);

        return Inertia::render('sales/Receipt', [
            'sale' => $pageData->sale($sale),
            'business' => $pageData->business($sale->business),
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function paymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => __('Cash')],
            ['value' => 'card', 'label' => __('Card')],
            ['value' => 'bank_transfer', 'label' => __('Bank transfer')],
            ['value' => 'other', 'label' => __('Other')],
        ];
    }
}
