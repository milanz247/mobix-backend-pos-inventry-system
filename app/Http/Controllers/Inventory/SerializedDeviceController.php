<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpdateSerializedDeviceStatusRequest;
use App\Models\SerializedDevice;
use App\SerializedDeviceStatus;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SerializedDeviceController extends Controller
{
    public function index(Request $request, LocationAccessService $locationAccess, InventoryPageData $pageData): Response
    {
        $business = $locationAccess->business();
        $locationIds = $locationAccess->accessibleQuery($request->user())->pluck('id');
        $search = $request->string('search')->trim()->toString();
        $locationId = $request->integer('location_id') ?: null;
        $status = SerializedDeviceStatus::tryFrom($request->string('status')->toString());

        $devices = $business->serializedDevices()->with([
            'variant:id,product_id,name,sku', 'variant.product:id,name', 'location:id,name',
            'events' => fn ($query) => $query->where('business_id', $business->id)
                ->where(fn ($query) => $query->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds))
                ->with(['fromLocation:id,name', 'toLocation:id,name', 'performer:id,name'])->latest('created_at')->limit(8),
        ])
            ->whereIn('location_id', $locationIds)
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status->value))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('imei', 'like', "%{$search}%")->orWhere('imei2', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhereHas('variant', fn (Builder $query) => $query->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")));
            }))
            ->latest('received_at')->paginate(20)->withQueryString()->through(fn (SerializedDevice $device): array => [
                'id' => $device->id, 'product' => $device->variant->product->name, 'variant' => $device->variant->name,
                'sku' => $device->variant->sku, 'imei' => $device->imei, 'imei2' => $device->imei2,
                'serial_number' => $device->serial_number, 'condition' => $device->condition->value,
                'status' => $device->status->value, 'location' => $device->location->name,
                'received_at' => $device->received_at->toIso8601String(),
                'history' => $device->events->map(fn ($event): array => [
                    'id' => $event->id,
                    'type' => $event->event_type->value,
                    'from' => $locationIds->contains($event->from_location_id) ? $event->fromLocation?->name : null,
                    'to' => $locationIds->contains($event->to_location_id) ? $event->toLocation?->name : null,
                    'status_after' => $event->status_after->value,
                    'actor' => $event->performer->name,
                    'created_at' => $event->created_at->toIso8601String(),
                ])->all(),
            ]);

        return Inertia::render('inventory/Devices', [
            'devices' => $devices, 'locations' => $pageData->locations($request->user()),
            'statuses' => collect(SerializedDeviceStatus::cases())->map(fn ($status): array => ['value' => $status->value, 'label' => $status->label()]),
            'filters' => ['search' => $search, 'location_id' => $locationId, 'status' => $status === null ? '' : $status->value],
        ]);
    }

    public function updateStatus(UpdateSerializedDeviceStatusRequest $request, SerializedDevice $serializedDevice, InventoryService $inventory): RedirectResponse
    {
        $inventory->changeDeviceStatus($request->user(), $serializedDevice, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device status updated with an audit event.')]);

        return back();
    }
}
