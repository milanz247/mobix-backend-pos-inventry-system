<?php

namespace App\Services;

use App\Models\User;

class ShopAttentionService
{
    public function __construct(
        private readonly LocationAccessService $access,
        private readonly CustomerAccountService $accounts,
        private readonly StockAvailabilityService $availability,
        private readonly CashierShiftService $shifts,
    ) {}

    /** @return list<array{label: string, value: int, href: string}> */
    public function forUser(User $user, ?int $locationId): array
    {
        $business = $this->access->business();
        $items = [];
        if ($user->can('sales.view') && $user->can('credit-sales.view')) {
            $overdue = $this->accounts->balances($business, $user)->where('is_credit', true)
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
                ->where('balance_due', '>', 0)->where('due_date', '<', now($business->timezone)->toDateString())->count();
            $items[] = ['label' => 'Overdue credit invoices', 'value' => $overdue, 'href' => '/sales/credit?overdue=1'.($locationId ? '&location_id='.$locationId : '')];
        }
        if ($user->can('inventory.view')) {
            $count = 0;
            foreach ($this->access->accessibleQuery($user)->where('status', 'active')->when($locationId !== null, fn ($q) => $q->whereKey($locationId))->get() as $location) {
                $count += $this->availability->query($business, $location)->where('status', 'active')
                    ->whereHas('product', fn ($q) => $q->where('status', 'active'))
                    ->whereNotNull('reorder_quantity')->whereColumn('available_quantity', '<=', 'reorder_quantity')->count();
            }
            $items[] = ['label' => 'Low-stock items by location', 'value' => $count, 'href' => '/inventory/reorder?alerts=1'.($locationId ? '&location_id='.$locationId : '')];
        }
        if ($user->can('shifts.view')) {
            $count = $this->shifts->accessibleQuery($user)->where('status', 'pending_approval')->whereNull('approved_at')->where('variance', '<>', 0)
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->count();
            $items[] = ['label' => 'Unapproved cash differences', 'value' => $count, 'href' => '/shifts?status=pending_approval'];
        }

        return $items;
    }
}
