<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesActivityService
{
    public function __construct(private readonly LocationAccessService $access) {}

    public function query(User $user, Business $business, ?int $locationId = null): Builder
    {
        $additions = DB::table('sale_additions')->where('business_id', $business->id)->selectRaw('sale_id, SUM(total) AS total')->groupBy('sale_id');
        $exchanges = DB::table('sale_returns')->where('business_id', $business->id)->where('is_exchange', true)->selectRaw('sale_id, SUM(total) AS total')->groupBy('sale_id');
        $original = DB::table('sales as s')->where('s.business_id', $business->id)
            ->leftJoinSub($additions, 'a', 'a.sale_id', '=', 's.id')->leftJoinSub($exchanges, 'r', 'r.sale_id', '=', 's.id')
            ->select('s.id as sale_id', 's.sale_number', 's.location_id', 's.cashier_id', 's.cashier_shift_id', 's.posted_at', 's.status', 's.customer_name')
            ->selectRaw("s.total - COALESCE(a.total, 0) - COALESCE(r.total, 0) AS total, 'sale' AS event_type, 1 AS initial_sale, s.id AS event_id");
        $added = DB::table('sale_additions as a')->join('sales as s', 's.id', '=', 'a.sale_id')->where('s.business_id', $business->id)->where('a.business_id', $business->id)
            ->select('s.id as sale_id', 's.sale_number', 's.location_id', 'a.created_by as cashier_id', 'a.cashier_shift_id', 'a.created_at as posted_at', 's.status', 's.customer_name')
            ->selectRaw("a.total, 'addition' AS event_type, 0 AS initial_sale, a.id AS event_id");
        $replaced = DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('s.business_id', $business->id)->where('r.business_id', $business->id)->where('r.is_exchange', true)
            ->select('s.id as sale_id', 's.sale_number', 's.location_id', 'r.processed_by as cashier_id', 'r.cashier_shift_id', 'r.processed_at as posted_at', 's.status', 's.customer_name')
            ->selectRaw("r.total, 'replacement' AS event_type, 0 AS initial_sale, r.id AS event_id");

        return DB::query()->fromSub($original->unionAll($added)->unionAll($replaced), 'sales_activity')
            ->whereIn('location_id', $this->access->accessibleQuery($user)->select('locations.id'))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId));
    }

    public function lines(User $user, Business $business, ?int $locationId = null): Builder
    {
        $lines = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->leftJoin('sale_additions as a', 'a.id', '=', 'l.sale_addition_id')
            ->leftJoin('sale_returns as r', 'r.id', '=', 'l.sale_return_id')
            ->where('s.business_id', $business->id)
            ->select('l.*', 's.location_id')->selectRaw('COALESCE(r.processed_at, a.created_at, s.posted_at) AS event_at, COALESCE(r.processed_by, a.created_by, s.cashier_id) AS event_actor_id');

        return DB::query()->fromSub($lines, 'activity_lines')
            ->whereIn('location_id', $this->access->accessibleQuery($user)->select('locations.id'))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId));
    }
}
