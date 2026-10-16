<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseReturnRequest;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\PurchaseStatus;
use App\Services\LocationAccessService;
use App\Services\PurchaseService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends Controller
{
    public function create(Purchase $purchase, PurchaseService $purchases, LocationAccessService $locationAccess): Response
    {
        $purchase = $purchases->scoped(request()->user(), $purchase);
        if ($purchase->status === PurchaseStatus::Draft) {
            abort(404);
        }

        $locations = $locationAccess->accessibleQuery(request()->user())->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'code']);
        $locationIds = $locations->modelKeys();
        $purchase->load(['lines.variant.product', 'lines.devices.serializedDevice.location']);

        return Inertia::render('purchasing/purchases/Return', [
            'purchase' => [
                'id' => $purchase->id,
                'invoice_number' => $purchase->invoice_number,
                'lines' => $purchase->lines->map(function (PurchaseLine $line) use ($locationIds): array {
                    $returned = $line->returnLines()->sum('quantity');
                    $remaining = BigDecimal::max(
                        BigDecimal::of((string) $line->quantity)->minus((string) $returned),
                        BigDecimal::zero(),
                    )->toScale(3, RoundingMode::HalfUp)->__toString();

                    return [
                        'id' => $line->id,
                        'product_variant_id' => $line->product_variant_id,
                        'label' => $line->variant->product->name.' — '.$line->variant->name,
                        'sku' => $line->variant->sku,
                        'tracking_mode' => $line->tracking_mode->value,
                        'quantity' => $line->quantity,
                        'remaining_quantity' => $remaining,
                        'stock' => $line->tracking_mode->value === 'quantity_based'
                            ? InventoryStock::query()->where('product_variant_id', $line->product_variant_id)
                                ->whereIn('location_id', $locationIds)->pluck('quantity_on_hand', 'location_id')
                            : [],
                        'devices' => $line->devices->filter(fn ($device): bool => $device->serializedDevice !== null
                            && in_array($device->serializedDevice->location_id, $locationIds, true)
                            && $device->serializedDevice->status->value === 'in_stock')
                            ->map(fn ($device): array => [
                                'id' => $device->serialized_device_id,
                                'imei' => $device->imei,
                                'condition' => $device->condition->value,
                                'location_id' => $device->serializedDevice->location_id,
                                'location_name' => $device->serializedDevice->location->name,
                            ])->values()->all(),
                    ];
                })->values()->all(),
            ],
            'locations' => $locations->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name, 'code' => $location->code])->values()->all(),
            'returnNumber' => $this->nextReturnNumber($purchase),
            'currency' => $purchase->business->currency,
        ]);
    }

    public function store(StorePurchaseReturnRequest $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $purchaseReturn = $purchases->createReturn($request->user(), $purchase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase return posted and inventory reversed.')]);

        return to_route('purchases.show', $purchaseReturn->purchase_id);
    }

    private function nextReturnNumber(Purchase $purchase): string
    {
        $sequence = $purchase->returns()->count() + 1;

        return 'PR-'.$purchase->id.'-'.$sequence;
    }
}
