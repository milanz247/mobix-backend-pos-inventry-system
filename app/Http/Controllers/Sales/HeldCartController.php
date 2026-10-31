<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\HeldCart;
use App\ProductType;
use App\Services\LocationAccessService;
use App\Services\StockAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HeldCartController extends Controller
{
    public function index(Request $request, LocationAccessService $access): JsonResponse
    {
        $request->validate(['location_id' => ['required', 'integer']]);
        $location = $access->accessibleQuery($request->user())->findOrFail($request->integer('location_id'));

        return response()->json(HeldCart::query()->where('business_id', $access->business()->id)->where('location_id', $location->id)->oldest()->get()->map(fn (HeldCart $cart): array => [
            ...$cart->payload, 'id' => $cart->id, 'location_id' => $cart->location_id, 'held_at' => $cart->created_at->toIso8601String(),
        ]));
    }

    public function store(Request $request, LocationAccessService $access, StockAvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'], 'location_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.variant' => ['required', 'array'], 'lines.*.variant.id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'lines.*.discount_amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'lines.*.device_identifier' => ['nullable', 'string', 'max:32'],
            'order_discount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'customer_name' => ['nullable', 'string', 'max:150'], 'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:254'], 'customer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'], 'sale_source' => ['nullable', 'string', 'max:50'],
        ]);
        abort_if(strlen($request->getContent()) > 200000, 413);
        $business = $access->business();
        $location = $access->accessibleQuery($request->user())->where('status', 'active')->findOrFail((int) $data['location_id']);
        $variants = $availability->query($business, $location)->with(['product.category', 'product.brand'])->whereIn('id', array_column(array_column($data['lines'], 'variant'), 'id'))->get()->keyBy('id');
        foreach ($data['lines'] as &$line) {
            $variant = $variants->get((int) $line['variant']['id']);
            abort_if($variant === null, 422);
            $line['variant'] = [
                'id' => $variant->id, 'name' => $variant->product->name, 'variant' => $variant->name,
                'sku' => $variant->sku, 'barcode' => $variant->barcode, 'category' => $variant->product->category->name,
                'brand' => $variant->product->brand?->name, 'product_type' => $variant->product->product_type->value,
                'tracking_mode' => $variant->tracking_mode->value, 'price' => $variant->selling_price,
                'minimum_selling_price' => $variant->minimum_selling_price, 'image_url' => null,
                'quantity_on_hand' => $variant->getAttribute('quantity_on_hand'), 'devices_in_stock' => (int) $variant->getAttribute('devices_in_stock'),
                'is_virtual_combo' => $variant->product->product_type === ProductType::Combo,
                'is_service' => $variant->product->product_type === ProductType::Service,
                ...$availability->describe($variant),
            ];
        }
        unset($line);
        $cart = DB::transaction(function () use ($data, $business, $location, $request): HeldCart {
            // Serialize saves at a location; no cart is silently discarded when the queue is full.
            $location->newQuery()->whereKey($location->id)->lockForUpdate()->firstOrFail();
            $existing = HeldCart::query()->whereKey((string) $data['id'])->first();
            if ($existing !== null) {
                abort_unless($existing->business_id === $business->id && $existing->location_id === $location->id && $existing->created_by === $request->user()->id, 404);

                return $existing;
            }
            if (HeldCart::query()->where('business_id', $business->id)->where('location_id', $location->id)->count() >= 100) {
                throw ValidationException::withMessages(['lines' => 'This location has 100 held carts. Resume or remove a cart before saving another.']);
            }
            $ids = array_column(array_column($data['lines'], 'variant'), 'id');
            abort_unless($business->productVariants()->whereIn('id', $ids)->count() === count(array_unique($ids)), 422);

            return HeldCart::query()->create(['id' => $data['id'], 'business_id' => $business->id, 'location_id' => $location->id, 'created_by' => $request->user()->id, 'payload' => $data]);
        });

        return response()->json(['id' => $cart->id], 201);
    }

    public function acknowledge(Request $request, string $cart, LocationAccessService $access): JsonResponse
    {
        return DB::transaction(function () use ($request, $cart, $access) {
            $held = HeldCart::query()->lockForUpdate()->find($cart);
            if ($held === null) {
                return response()->json(['acknowledged' => true]);
            }
            abort_unless($held->business_id === $access->business()->id && $access->accessibleQuery($request->user())->whereKey($held->location_id)->exists(), 404);
            abort_unless((int) $held->claimed_by === $request->user()->id, 403);
            $held->delete();

            return response()->json(['acknowledged' => true]);
        });
    }

    public function claim(Request $request, HeldCart $cart, LocationAccessService $access): JsonResponse
    {
        abort_unless($cart->business_id === $access->business()->id && $access->accessibleQuery($request->user())->whereKey($cart->location_id)->exists(), 404);

        return DB::transaction(function () use ($cart, $request): JsonResponse {
            $locked = HeldCart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $payload = [...$locked->payload, 'id' => $locked->id, 'location_id' => $locked->location_id, 'held_at' => $locked->created_at->toIso8601String()];
            abort_if($locked->claimed_by !== null && (int) $locked->claimed_by !== $request->user()->id && $locked->claimed_at > now()->subMinutes(5)->toDateTimeString(), 409, 'Another cashier is resuming this cart.');
            $locked->forceFill(['claimed_by' => $request->user()->id, 'claimed_at' => now()])->save();

            return response()->json($payload);
        });
    }
}
