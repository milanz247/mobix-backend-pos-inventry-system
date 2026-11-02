<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\StorefrontCartException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\OrderOfferResource;
use App\Models\Business;
use App\Models\WebDeliveryArea;
use App\Services\StorefrontCartService;
use App\Services\StorefrontOrderOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderOfferController extends Controller
{
    public function deliveryAreas(): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $areas = WebDeliveryArea::query()->where('business_id', $business->id)->where('is_active', true)
            ->where('currency', $business->currency)
            ->whereHas('mappings', fn ($query) => $query->where('business_id', $business->id)->where('is_active', true)
                ->whereHas('location', fn ($location) => $location->where('business_id', $business->id)->where('status', 'active')))
            ->orderBy('name')->get(['code', 'name', 'description']);

        return response()->json(['data' => $areas->map(fn ($area) => [
            'code' => $area->code, 'name' => $area->name, 'description' => $area->description,
        ])->all()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, StorefrontCartService $carts, StorefrontOrderOfferService $offers): JsonResponse
    {
        $input = $request->validate([
            'quote_reference' => ['required', 'string', 'ulid'],
            'fulfillment_mode' => ['required', Rule::in(['home_delivery', 'shop_pickup'])],
            'delivery_area_code' => ['required_if:fulfillment_mode,home_delivery', 'prohibited_if:fulfillment_mode,shop_pickup', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'pickup_location_code' => ['required_if:fulfillment_mode,shop_pickup', 'prohibited_if:fulfillment_mode,home_delivery', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+(?:[-_][A-Za-z0-9]+)*$/'],
            'payment_method' => ['required', Rule::in(['cod', 'bank_transfer'])],
            'contact' => ['required', 'array:name,phone,email'],
            'contact.name' => ['required', 'string', 'max:150'],
            'contact.phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s-]{6,28}$/'],
            'contact.email' => ['nullable', 'email', 'max:254'],
            'address' => ['required_if:fulfillment_mode,home_delivery', 'prohibited_if:fulfillment_mode,shop_pickup', 'array:line1,line2,city,postal_code'],
            'address.line1' => ['required_if:fulfillment_mode,home_delivery', 'string', 'max:200'],
            'address.line2' => ['nullable', 'string', 'max:200'],
            'address.city' => ['required_if:fulfillment_mode,home_delivery', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:30'],
        ]);
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $cart = $carts->find($business, $request->header('X-Storefront-Cart-Token'))
            ?? throw new StorefrontCartException('CART_NOT_FOUND', 'The cart could not be found.', 404);

        return (new OrderOfferResource($offers->create($business, $cart, $input)))->response()
            ->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }
}
