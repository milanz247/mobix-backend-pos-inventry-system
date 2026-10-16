<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchasePaymentRequest;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PurchasePaymentController extends Controller
{
    public function store(StorePurchasePaymentRequest $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $purchases->recordPayment($request->user(), $purchase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier payment recorded.')]);

        return to_route('purchases.show', $purchase);
    }
}
