<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateInvoiceSettingsRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceSettingsController extends Controller
{
    public function edit(): Response
    {
        $business = Business::query()->sole();

        return Inertia::render('settings/Invoices', [
            'invoice' => [
                'footer' => $business->invoice_footer,
                'return_policy' => $business->invoice_return_policy,
                'title' => $business->invoice_title,
                'paper_size' => $business->invoice_paper_size,
                'accent_color' => $business->invoice_accent_color,
                'show_logo' => $business->invoice_show_logo,
                'show_business_details' => $business->invoice_show_business_details,
                'show_customer' => $business->invoice_show_customer,
                'show_cashier' => $business->invoice_show_cashier,
                'show_payment_details' => $business->invoice_show_payment_details,
                'show_tax' => $business->invoice_show_tax,
            ],
        ]);
    }

    public function update(UpdateInvoiceSettingsRequest $request): RedirectResponse
    {
        $request->business()->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invoice settings updated.'),
        ]);

        return back();
    }
}
