<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\RecordSalePaymentRequest;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SalePaymentController extends Controller
{
    public function store(RecordSalePaymentRequest $request, Sale $sale, SaleService $sales): RedirectResponse
    {
        $sales->recordPayment($request->user(), $sale, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return to_route('sales.show', $sale);
    }
}
