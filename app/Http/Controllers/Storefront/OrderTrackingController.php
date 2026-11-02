<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\StorefrontOrderTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    public function show(Request $request, string $reference, StorefrontOrderTrackingService $tracking): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();

        return response()->json(['data' => $tracking->show($business, $reference,
            $request->header('X-Storefront-Order-Credential'))])
            ->header('Cache-Control', 'private, no-store');
    }
}
