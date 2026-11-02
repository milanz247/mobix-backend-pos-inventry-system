<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\WebPickupLocationService;
use Illuminate\Http\JsonResponse;

class PickupLocationController extends Controller
{
    public function index(WebPickupLocationService $pickups): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $locations = $pickups->publicQuery($business)->with('location')
            ->orderBy('selection_priority')->orderBy('public_code')->get();

        return response()->json(['data' => $locations->map(fn ($pickup) => $pickups->publicSnapshot($pickup))->all()])
            ->header('Cache-Control', 'private, no-store');
    }
}
