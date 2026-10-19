<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Services\LocationAccessService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess): Response
    {
        $business = $locationAccess->business();
        $locationId = $request->integer('location_id') ?: null;
        $showCosts = $request->user()->can('sales.costs.view');
        $businessNow = Carbon::now($business->timezone);
        $todayStart = $businessNow->copy()->startOfDay()->utc();
        $todayEnd = $businessNow->copy()->endOfDay()->utc();
        $trendStart = $businessNow->copy()->subDays(29)->startOfDay()->utc();
        $timezoneOffset = $businessNow->format('P');
        $accessibleLocationIds = $locationAccess->accessibleQuery($request->user())
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))->select('locations.id');
        $accessibleSales = Sale::query()
            ->where('sales.business_id', $business->id)
            ->whereIn('sales.location_id', clone $accessibleLocationIds);
        $accessibleSaleIds = (clone $accessibleSales)->select('sales.id');

        $todaySales = (clone $accessibleSales)->whereBetween('sales.posted_at', [$todayStart, $todayEnd]);
        $todayReturns = SaleReturn::query()
            ->where('business_id', $business->id)
            ->whereIn('location_id', clone $accessibleLocationIds)
            ->whereBetween('processed_at', [$todayStart, $todayEnd]);
        $todayRefunds = SaleRefund::query()
            ->where('business_id', $business->id)
            ->whereIn('sale_id', clone $accessibleSaleIds)
            ->whereBetween('refunded_at', [$todayStart, $todayEnd]);
        $todayCashReceived = SalePayment::query()
            ->where('business_id', $business->id)
            ->whereIn('sale_id', clone $accessibleSaleIds)
            ->where('method', 'cash')
            ->whereBetween('paid_at', [$todayStart, $todayEnd])
            ->sum('amount');

        $returnTotals = SaleReturn::query()
            ->selectRaw('sale_id, SUM(total) as returned_total')
            ->where('business_id', $business->id)
            ->groupBy('sale_id');
        $refundTotals = SaleRefund::query()
            ->selectRaw('sale_id, SUM(amount) as refunded_total')
            ->where('business_id', $business->id)
            ->groupBy('sale_id');
        $outstanding = (clone $accessibleSales)
            ->leftJoinSub($returnTotals, 'return_totals', 'return_totals.sale_id', '=', 'sales.id')
            ->leftJoinSub($refundTotals, 'refund_totals', 'refund_totals.sale_id', '=', 'sales.id')
            ->selectRaw('COALESCE(SUM(GREATEST(GREATEST(sales.total - COALESCE(return_totals.returned_total, 0), 0) - GREATEST(sales.paid_total - COALESCE(refund_totals.refunded_total, 0), 0), 0)), 0) as outstanding_total')
            ->first();

        $dailySales = (clone $accessibleSales)
            ->whereBetween('sales.posted_at', [$trendStart, $todayEnd])
            ->selectRaw("DATE(CONVERT_TZ(sales.posted_at, '+00:00', ?)) as day, COUNT(*) as sales_count, SUM(sales.total) as gross_sales", [$timezoneOffset])
            ->groupBy('day')
            ->get()
            ->keyBy(fn (Sale $sale): string => (string) $sale->getAttribute('day'));
        $dailyReturns = SaleReturn::query()
            ->where('sale_returns.business_id', $business->id)
            ->whereIn('sale_returns.location_id', clone $accessibleLocationIds)
            ->whereBetween('sale_returns.processed_at', [$trendStart, $todayEnd])
            ->selectRaw("DATE(CONVERT_TZ(sale_returns.processed_at, '+00:00', ?)) as day, SUM(sale_returns.total) as returns_total", [$timezoneOffset])
            ->groupBy('day')
            ->get()
            ->keyBy(fn (SaleReturn $return): string => (string) $return->getAttribute('day'));
        $dailySaleProfit = $showCosts ? SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.business_id', $business->id)
            ->whereIn('sales.location_id', clone $accessibleLocationIds)
            ->whereBetween('sales.posted_at', [$trendStart, $todayEnd])
            ->selectRaw("DATE(CONVERT_TZ(sales.posted_at, '+00:00', ?)) as day, SUM(sale_lines.total - (sale_lines.cost_snapshot * sale_lines.quantity)) as gross_profit", [$timezoneOffset])
            ->groupBy('day')
            ->get()
            ->keyBy(fn (SaleLine $line): string => (string) $line->getAttribute('day')) : collect();
        $dailyReturnedProfit = $showCosts ? SaleReturnLine::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
            ->where('sale_returns.business_id', $business->id)
            ->whereIn('sale_returns.location_id', clone $accessibleLocationIds)
            ->where('sale_return_lines.is_component', false)
            ->whereBetween('sale_returns.processed_at', [$trendStart, $todayEnd])
            ->selectRaw("DATE(CONVERT_TZ(sale_returns.processed_at, '+00:00', ?)) as day, SUM(sale_return_lines.total - (sale_return_lines.unit_cost_snapshot * sale_return_lines.quantity)) as returned_profit", [$timezoneOffset])
            ->groupBy('day')
            ->get()
            ->keyBy(fn (SaleReturnLine $line): string => (string) $line->getAttribute('day')) : collect();

        $netSalesThirtyDays = BigDecimal::zero();
        $grossProfitThirtyDays = BigDecimal::zero();
        $trend = collect(range(29, 0))->map(function (int $daysAgo) use ($businessNow, $dailySales, $dailyReturns, $dailySaleProfit, $dailyReturnedProfit, &$netSalesThirtyDays, &$grossProfitThirtyDays): array {
            $day = $businessNow->copy()->subDays($daysAgo)->toDateString();
            $sales = $dailySales->get($day);
            $returns = $dailyReturns->get($day);
            $saleProfit = $dailySaleProfit->get($day);
            $returnedProfit = $dailyReturnedProfit->get($day);
            $netSales = BigDecimal::of((string) ($sales?->getAttribute('gross_sales') ?? '0'))
                ->minus((string) ($returns?->getAttribute('returns_total') ?? '0'));
            $grossProfit = BigDecimal::of((string) ($saleProfit?->getAttribute('gross_profit') ?? '0'))
                ->minus((string) ($returnedProfit?->getAttribute('returned_profit') ?? '0'));
            $netSalesThirtyDays = $netSalesThirtyDays->plus($netSales);
            $grossProfitThirtyDays = $grossProfitThirtyDays->plus($grossProfit);

            return [
                'date' => $day,
                'sales_count' => (int) ($sales?->getAttribute('sales_count') ?? 0),
                'net_sales' => $this->money($netSales),
                'gross_profit' => $this->money($grossProfit),
            ];
        })->values();

        return Inertia::render('Dashboard', [
            'overview' => [
                'sales_total' => $this->money((string) (clone $todaySales)->sum('sales.total')),
                'completed_sales' => (clone $todaySales)->count(),
                'cash_received' => $this->money((string) $todayCashReceived),
                'returns' => $this->money((string) (clone $todayReturns)->sum('total')),
                'refunds' => $this->money((string) (clone $todayRefunds)->sum('amount')),
                'outstanding' => $this->money((string) ($outstanding?->getAttribute('outstanding_total') ?? '0')),
            ],
            'performance' => [
                'net_sales_30_days' => $this->money($netSalesThirtyDays),
                'gross_profit_30_days' => $showCosts ? $this->money($grossProfitThirtyDays) : null,
            ],
            'trend' => $trend->map(fn (array $row): array => [...$row, 'gross_profit' => $showCosts ? $row['gross_profit'] : null]),
            'canViewCosts' => $showCosts,
            'locations' => $locationAccess->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']),
            'filters' => ['location_id' => $locationId ?? ''],
            'currency' => $business->currency,
            'generatedAt' => $businessNow->toIso8601String(),
        ]);
    }

    private function money(BigDecimal|string $value): string
    {
        return BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp)->__toString();
    }
}
