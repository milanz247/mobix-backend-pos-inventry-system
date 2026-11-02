<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WebHomeBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class HomeBannerController extends Controller
{
    public function show(): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $banner = WebHomeBanner::query()->where('business_id', $business->id)->where('is_active', true)->orderByDesc('id')->first();
        $image = $banner?->image_path;
        if ($image !== null && ! preg_match('~^storefront-banners/[A-Za-z0-9/_-]+\.(?:jpg|jpeg|png|webp)$~i', $image)) {
            $image = null;
        }

        return response()->json(['data' => $banner === null ? null : [
            'headline' => $banner->headline, 'eyebrow' => $banner->eyebrow,
            'description' => $banner->description, 'button_text' => $banner->button_text,
            'button_href' => $banner->button_href,
            'image_url' => $image === null ? null : Storage::disk('public')->url($image),
            'image_alt' => $banner->image_alt,
        ]])->header('Cache-Control', 'private, no-store');
    }
}
