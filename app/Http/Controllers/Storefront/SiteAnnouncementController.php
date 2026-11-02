<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WebStorefrontSetting;
use Illuminate\Http\JsonResponse;

class SiteAnnouncementController extends Controller
{
    public function contact(): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();

        return response()->json(['data' => [
            'name' => $business->name, 'phone' => $business->phone,
            'email' => $business->email, 'address' => $business->address, 'city' => $business->city,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function shoppingStatus(): JsonResponse
    {
        return response()->json(['data' => [
            'orders_enabled' => (bool) config('storefront.order_submission_enabled', false),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $settings = WebStorefrontSetting::query()->where('business_id', $business->id)->first();

        return response()->json(['data' => $settings === null ? null : [
            'text' => $settings->announcement_text,
            'direction' => $settings->announcement_direction,
            'enabled' => $settings->announcement_enabled,
        ]])->header('Cache-Control', 'private, no-store');
    }
}
