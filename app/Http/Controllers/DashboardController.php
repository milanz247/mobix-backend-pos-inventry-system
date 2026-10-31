<?php

namespace App\Http\Controllers;

use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Services\LocationAccessService;
use App\Services\SalesActivityService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess, SalesActivityService $activity): Response
    {
        $business = $locationAccess->business();
        $locationId = $request->integer('location_id') ?: null;
        $showCosts = $request->user()->can('sales.costs.view');
        $businessNow = Carbon::now($business->timezone);
        $todayStart = $businessNow->copy()->startOfDay();
        $todayEnd = $businessNow->copy()->endOfDay();
        $trendStart = $businessNow->copy()->subDays(29)->startOfDay();
        $accessibleLocationIds = $locationAccess->accessibleQuery($request->user())
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))->select('locations.id');
        $activityQuery = $activity->query($request->user(), $business, $locationId);
        $todaySales = (clone $activityQuery)->whereBetween('posted_at', [$todayStart, $todayEnd]);
        $todayCashReceived = SalePayment::query()
            ->where('business_id', $business->id)
            ->whereIn('sale_id', $business->sales()->whereIn('location_id', clone $accessibleLocationIds)->select('sales.id'))
            ->where('method', 'cash')
            ->whereBetween('paid_at', [$todayStart, $todayEnd])
            ->sum('amount');
        $todayGrossProfit = null;
        if ($showCosts) {
            $salesProfit = $activity->lines($request->user(), $business, $locationId)
                ->whereBetween('event_at', [$todayStart, $todayEnd])
                ->selectRaw('SUM(total - tax_total - cost_snapshot * quantity) AS gross_profit')
                ->value('gross_profit');
            $returnedProfit = SaleReturnLine::query()
                ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
                ->where('sale_returns.business_id', $business->id)
                ->whereIn('sale_returns.location_id', clone $accessibleLocationIds)
                ->where('sale_return_lines.is_component', false)
                ->whereBetween('sale_returns.processed_at', [$todayStart, $todayEnd])
                ->sum(DB::raw('sale_return_lines.total - sale_return_lines.tax_total - (sale_return_lines.unit_cost_snapshot * sale_return_lines.quantity)'));
            $todayGrossProfit = BigDecimal::of((string) ($salesProfit ?? '0'))->minus((string) $returnedProfit);
        }

        $dailySales = (clone $activityQuery)->whereBetween('posted_at', [$trendStart, $todayEnd])
            ->selectRaw('DATE(posted_at) AS day, SUM(initial_sale) AS sales_count, SUM(total) AS gross_sales')
            ->groupBy('day')->get()->keyBy(fn (object $row): string => (string) $row->day);
        $dailyReturns = SaleReturn::query()
            ->where('sale_returns.business_id', $business->id)
            ->whereIn('sale_returns.location_id', clone $accessibleLocationIds)
            ->whereBetween('sale_returns.processed_at', [$trendStart, $todayEnd])
            ->selectRaw('DATE(sale_returns.processed_at) as day, SUM(sale_returns.total) as returns_total')
            ->groupBy('day')
            ->get()
            ->keyBy(fn (SaleReturn $return): string => (string) $return->getAttribute('day'));
        $dailySaleProfit = $showCosts ? $activity->lines($request->user(), $business, $locationId)
            ->whereBetween('event_at', [$trendStart, $todayEnd])
            ->selectRaw('DATE(event_at) AS day, SUM(total - tax_total - cost_snapshot * quantity) AS gross_profit')
            ->groupBy('day')->get()->keyBy(fn (object $row): string => (string) $row->day) : collect();
        $dailyReturnedProfit = $showCosts ? SaleReturnLine::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
            ->where('sale_returns.business_id', $business->id)
            ->whereIn('sale_returns.location_id', clone $accessibleLocationIds)
            ->where('sale_return_lines.is_component', false)
            ->whereBetween('sale_returns.processed_at', [$trendStart, $todayEnd])
            ->selectRaw('DATE(sale_returns.processed_at) as day, SUM(sale_return_lines.total - sale_return_lines.tax_total - (sale_return_lines.unit_cost_snapshot * sale_return_lines.quantity)) as returned_profit')
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
            $netSales = BigDecimal::of((string) ($sales->gross_sales ?? '0'))
                ->minus((string) ($returns?->getAttribute('returns_total') ?? '0'));
            $grossProfit = BigDecimal::of((string) ($saleProfit->gross_profit ?? '0'))
                ->minus((string) ($returnedProfit?->getAttribute('returned_profit') ?? '0'));
            $netSalesThirtyDays = $netSalesThirtyDays->plus($netSales);
            $grossProfitThirtyDays = $grossProfitThirtyDays->plus($grossProfit);

            return [
                'date' => $day,
                'sales_count' => (int) ($sales->sales_count ?? 0),
                'net_sales' => $this->money($netSales),
                'gross_profit' => $this->money($grossProfit),
            ];
        })->values();

        return Inertia::render('Dashboard', [
            'overview' => [
                'sales_total' => $this->money((string) (clone $todaySales)->sum('total')),
                'completed_sales' => (int) (clone $todaySales)->sum('initial_sale'),
                'cash_received' => $this->money((string) $todayCashReceived),
                'gross_profit_today' => $todayGrossProfit !== null ? $this->money($todayGrossProfit) : null,
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
