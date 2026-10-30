<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SerializedDevice;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use App\Support\SalePageData;
use App\Support\SaleReturnPageData;
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
            'replacementDevices' => fn () => SerializedDevice::query()->where('business_id', $sale->business_id)->where('location_id', $sale->location_id)->where('status', 'in_stock')->orderBy('imei')->get(['id', 'product_variant_id', 'imei']),
            'requireReplacementImei' => $sale->business->pos_require_imei_on_sale,
            'canExchange' => request()->user()->can('sales.create') && request()->user()->can('shifts.view') && request()->user()->can('sale-replacements.create'),
            'canRefund' => request()->user()->can('sale-refunds.create'),
            'remainingPaid' => BigDecimal::max(BigDecimal::of((string) $sale->paid_total)->minus((string) $sale->refunds()->sum('amount')), BigDecimal::zero())->toScale(2)->__toString(),
        ]);
    }

    public function receipt(Sale $sale, SaleReturn $saleReturn, SaleService $sales, SalePageData $pageData, SaleReturnPageData $returnData): Response
    {
        $sale = $sales->scoped(request()->user(), $sale);
        $return = $sale->returns()->whereKey($saleReturn->id)->firstOrFail();

        return Inertia::render('sales/ReturnReceipt', [
            'receipt' => $returnData->receipt($sale, $return, $sales),
            'business' => $pageData->business($sale->business),
        ]);
    }

    public function store(StoreSaleReturnRequest $request, Sale $sale, SaleService $sales): RedirectResponse
    {
        $validated = $request->validated();
        if (! ($validated['is_exchange'] ?? false) && filled($validated['refund_amount'] ?? null) && BigDecimal::of((string) $validated['refund_amount'])->isGreaterThan(BigDecimal::zero()) && ! filled($validated['refund_method'] ?? null)) {
            return back()->withErrors(['refund_method' => __('Select a refund method.')])->withInput();
        }
        $return = $sales->processReturn($request->user(), $sale, $validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Return :number processed.', ['number' => $return->return_number])]);

        return to_route('sales.show', $sale);
    }
}
