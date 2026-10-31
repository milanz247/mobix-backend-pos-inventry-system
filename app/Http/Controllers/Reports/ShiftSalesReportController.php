<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\LocationAccessService;
use App\Services\SalesActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShiftSalesReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access, CashierShiftService $shifts, SalesActivityService $activity): Response
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'location_id' => ['nullable', 'integer'], 'cashier_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['open', 'closed', 'pending_approval', 'approved'])]]);
        $business = $access->business();
        $user = $request->user();
        $sales = $activity->query($user, $business)->selectRaw('cashier_shift_id AS shift_id, SUM(total) AS issued_sales')->groupBy('cashier_shift_id');
        $payments = DB::table('sale_payments')->where('business_id', $business->id)->selectRaw('cashier_shift_id AS shift_id, SUM(amount) AS collections')->groupBy('cashier_shift_id');
        $refunds = DB::table('sale_refunds')->where('business_id', $business->id)->selectRaw('cashier_shift_id AS shift_id, SUM(amount) AS refunds')->groupBy('cashier_shift_id');
        $returns = DB::table('sale_returns')->where('business_id', $business->id)->selectRaw('cashier_shift_id AS shift_id, SUM(total) AS credits')->groupBy('cashier_shift_id');
        $query = $shifts->accessibleQuery($user)->leftJoinSub($sales, 'a', 'a.shift_id', '=', 'cashier_shifts.id')->leftJoinSub($payments, 'p', 'p.shift_id', '=', 'cashier_shifts.id')->leftJoinSub($refunds, 'f', 'f.shift_id', '=', 'cashier_shifts.id')->leftJoinSub($returns, 'r', 'r.shift_id', '=', 'cashier_shifts.id')
            ->select('cashier_shifts.*')->selectRaw('COALESCE(a.issued_sales,0)-COALESCE(r.credits,0) AS net_sales, COALESCE(p.collections,0) AS collections, COALESCE(f.refunds,0) AS refunds');
        foreach (['location_id', 'cashier_id', 'status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where('cashier_shifts.'.$field, $filters[$field]);
            }
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('opened_at', '>=', Carbon::parse($filters['date_from'], $business->timezone)->startOfDay());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('opened_at', '<', Carbon::parse($filters['date_to'], $business->timezone)->addDay()->startOfDay());
        }
        $aggregate = DB::query()->fromSub(clone $query, 'shift_report');
        $summary = ['shifts' => (clone $aggregate)->count(), 'net_sales' => (string) (clone $aggregate)->sum('net_sales'), 'collections' => (string) (clone $aggregate)->sum('collections'), 'refunds' => (string) (clone $aggregate)->sum('refunds'), 'variance' => (string) (clone $aggregate)->sum('variance')];

        return Inertia::render('reports/ShiftSales', ['summary' => $summary, 'rows' => $query->with(['cashier:id,name', 'location:id,name'])->latest('opened_at')->latest('cashier_shifts.id')->paginate(25)->withQueryString()->through(fn ($row): array => ['id' => $row->id, 'reference' => $row->reference, 'cashier' => $row->cashier->name, 'branch' => $row->location->name, 'opened_at' => $row->opened_at->toIso8601String(), 'closed_at' => $row->closed_at?->toIso8601String(), 'status' => $row->status, 'net_sales' => (string) $row->getAttribute('net_sales'), 'collections' => (string) $row->getAttribute('collections'), 'refunds' => (string) $row->getAttribute('refunds'), 'expected' => $row->expected_cash, 'counted' => $row->actual_cash, 'variance' => $row->variance]), 'filters' => $filters, 'currency' => $business->currency, 'timezone' => $business->timezone,
            'locations' => $access->accessibleQuery($user)->orderBy('name')->get(['id', 'name']), 'cashiers' => User::query()->whereIn('id', $shifts->accessibleQuery($user)->select('cashier_id'))->orderBy('name')->get(['id', 'name'])]);
    }
}
