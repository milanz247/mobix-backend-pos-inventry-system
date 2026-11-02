<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\StorefrontCartException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\CartResource;
use App\Http\Resources\Storefront\QuoteResource;
use App\Models\Business;
use App\Models\WebCart;
use App\Services\StorefrontCartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request, StorefrontCartService $carts): CartResource
    {
        $business = $this->business();

        return new CartResource($carts->view($business, $carts->find($business, $request->header('X-Storefront-Cart-Token'))));
    }

    public function add(Request $request, StorefrontCartService $carts): JsonResponse
    {
        $input = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,10'],
        ]);
        $business = $this->business();
        $result = $carts->add($business, $request->header('X-Storefront-Cart-Token'), (int) $input['product_id'], (int) $input['variant_id'], (int) $input['quantity']);
        $response = (new CartResource($carts->view($business, $result['cart'])))->response()->setStatusCode(201);
        if ($result['token'] !== null) {
            $response->headers->set('X-Storefront-Cart-Token', $result['token']);
        }

        return $response;
    }

    public function update(Request $request, string $item, StorefrontCartService $carts): CartResource
    {
        $input = $request->validate(['quantity' => ['required', 'integer', 'between:1,10']]);
        $business = $this->business();
        $cart = $this->requiredCart($request, $carts, $business);
        $carts->update($business, $cart, $item, (int) $input['quantity']);

        return new CartResource($carts->view($business, $cart->fresh()));
    }

    public function remove(Request $request, string $item, StorefrontCartService $carts): CartResource
    {
        $business = $this->business();
        $cart = $this->requiredCart($request, $carts, $business);
        $carts->remove($cart, $item);

        return new CartResource($carts->view($business, $cart->fresh()));
    }

    public function clear(Request $request, StorefrontCartService $carts): CartResource
    {
        $business = $this->business();
        $cart = $carts->find($business, $request->header('X-Storefront-Cart-Token'));
        if ($cart !== null) {
            $carts->clear($cart);
        }

        return new CartResource($carts->view($business, $cart?->fresh()));
    }

    public function quote(Request $request, StorefrontCartService $carts): QuoteResource
    {
        $business = $this->business();
        $cart = $this->requiredCart($request, $carts, $business);

        return new QuoteResource($carts->quote($business, $cart));
    }

    private function requiredCart(Request $request, StorefrontCartService $carts, Business $business): WebCart
    {
        return $carts->find($business, $request->header('X-Storefront-Cart-Token'))
            ?? throw new StorefrontCartException('CART_NOT_FOUND', 'The cart could not be found.', 404);
    }

    private function business(): Business
    {
        return Business::query()->where('setup_key', 'initial')->sole();
    }
}
