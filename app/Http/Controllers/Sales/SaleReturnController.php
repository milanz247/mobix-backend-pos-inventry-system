<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Models\Sale;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use App\Support\SalePageData;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SaleReturnController extends Controller
{
    public function create(Sale $sale, SaleService $sales, LocationAccessService $locations, SalePageData $pageData): Response
    {
        $sale = $sales->scoped(request()->user(), $sale);

        return Inertia::render('sales/Return', [
            'sale' => $pageData->sale($sale),
            'locations' => $locations->accessibleQuery(request()->user())->whereKey($sale->location_id)->get(['id', 'name', 'code']),
            'currency' => $sale->business->currency,
            'paymentMethods' => [
                ['value' => 'cash', 'label' => __('Cash')],
                ['value' => 'card', 'label' => __('Card')],
                ['value' => 'bank_transfer', 'label' => __('Bank transfer')],
                ['value' => 'other', 'label' => __('Other')],
            ],
            'canRefund' => request()->user()->can('sale-refunds.create'),
            'remainingPaid' => BigDecimal::max(BigDecimal::of((string) $sale->paid_total)->minus((string) $sale->refunds()->sum('amount')), BigDecimal::zero())->toScale(2)->__toString(),
        ]);
    }

    public function store(StoreSaleReturnRequest $request, Sale $sale, SaleService $sales): RedirectResponse
    {
        $validated = $request->validated();
        if (filled($validated['refund_amount'] ?? null) && BigDecimal::of((string) $validated['refund_amount'])->isGreaterThan(BigDecimal::zero()) && ! filled($validated['refund_method'] ?? null)) {
            return back()->withErrors(['refund_method' => __('Select a refund method.')])->withInput();
        }
        $return = $sales->processReturn($request->user(), $sale, $validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Return :number processed.', ['number' => $return->return_number])]);

        return to_route('sales.show', $sale);
    }
}
