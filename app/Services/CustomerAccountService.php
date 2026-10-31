<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerAccountService
{
    public function __construct(private readonly LocationAccessService $locations) {}

    /** @return Builder<Sale> */
    public function balances(Business $business, ?User $user = null): Builder
    {
        $returns = DB::table('sale_returns')->where('business_id', $business->id)->selectRaw('sale_id, SUM(total) AS returned')->groupBy('sale_id');
        $refunds = DB::table('sale_refunds')->where('business_id', $business->id)->selectRaw('sale_id, SUM(amount) AS refunded')->groupBy('sale_id');
        $sales = Sale::query()->where('sales.business_id', $business->id)
            ->leftJoinSub($returns, 'r', 'r.sale_id', '=', 'sales.id')
            ->leftJoinSub($refunds, 'f', 'f.sale_id', '=', 'sales.id')
            ->select('sales.*')->selectRaw('GREATEST(sales.total - COALESCE(r.returned, 0), 0) AS net_total, GREATEST(sales.paid_total - COALESCE(f.refunded, 0), 0) AS net_paid')->selectRaw('GREATEST(GREATEST(sales.total - COALESCE(r.returned, 0), 0) - GREATEST(sales.paid_total - COALESCE(f.refunded, 0), 0), 0) AS balance_due');
        if ($user !== null) {
            $sales->whereIn('sales.location_id', $this->locations->accessibleQuery($user)->select('locations.id'));
            if (! $user->can('credit-sales.view')) {
                $sales->where('sales.is_credit', false);
            }
        }

        return Sale::query()->fromSub($sales, 'sales')->select('sales.*');
    }
}
