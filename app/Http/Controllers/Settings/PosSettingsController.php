<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePosSettingsRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosSettingsController extends Controller
{
    public function edit(): Response
    {
        $business = Business::query()->sole();

        return Inertia::render('settings/Pos', [
            'settings' => [
                'show_sale_source' => $business->pos_show_sale_source,
                'show_customer_name' => $business->pos_show_customer_name,
                'show_customer_phone' => $business->pos_show_customer_phone,
                'show_customer_email' => $business->pos_show_customer_email,
                'show_sale_notes' => $business->pos_show_sale_notes,
                'show_pay_exact' => $business->pos_show_pay_exact,
                'show_date_time' => $business->pos_show_date_time,
                'show_sales_history' => $business->pos_show_sales_history,
                'show_category_filter' => $business->pos_show_category_filter,
                'show_brand_filter' => $business->pos_show_brand_filter,
                'show_location_filter' => $business->pos_show_location_filter,
                'show_receipt_after_sale' => $business->pos_show_receipt_after_sale,
                'products_per_page' => $business->pos_products_per_page,
            ],
        ]);
    }

    public function update(UpdatePosSettingsRequest $request): RedirectResponse
    {
        $request->business()->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('POS screen settings updated.'),
        ]);

        return back();
    }
}
