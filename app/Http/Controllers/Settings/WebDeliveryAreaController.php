<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WebDeliveryArea;
use App\Models\WebLocationDeliveryArea;
use App\PermissionName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebDeliveryAreaController extends Controller
{
    public function index(): Response
    {
        $this->authorizeManage();
        $business = $this->business();
        $areas = WebDeliveryArea::query()->where('business_id', $business->id)->with(['mappings' => fn ($query) => $query->with('location:id,name,status')->orderBy('selection_priority')->orderBy('location_id')])->orderBy('name')->get();

        return Inertia::render('settings/StorefrontDelivery', [
            'areas' => $areas->map(fn ($area) => [
                'id' => $area->id, 'code' => $area->code, 'name' => $area->name, 'description' => $area->description,
                'base_delivery_fee' => $area->base_delivery_fee, 'currency' => $area->currency, 'is_active' => $area->is_active,
                'mappings' => $area->mappings->map(fn ($mapping) => [
                    'id' => $mapping->id, 'location_id' => $mapping->location_id, 'location_name' => $mapping->location->name,
                    'location_status' => $mapping->location->status->value, 'delivery_fee_override' => $mapping->delivery_fee_override,
                    'selection_priority' => $mapping->selection_priority, 'is_active' => $mapping->is_active,
                ])->all(),
            ])->all(),
            'locations' => $business->locations()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($location) => [
                'id' => $location->id, 'name' => $location->name, 'status' => $location->status->value,
            ])->all(),
            'currency' => $business->currency,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $business = $this->business();
        $data = $this->areaData($request, $business);
        WebDeliveryArea::query()->create($data + ['business_id' => $business->id, 'currency' => $business->currency]);

        return to_route('storefront-delivery.index');
    }

    public function update(Request $request, WebDeliveryArea $area): RedirectResponse
    {
        $this->authorizeManage();
        $business = $this->business();
        $area = WebDeliveryArea::query()->where('business_id', $business->id)->findOrFail($area->id);
        $area->update($this->areaData($request, $business, $area->id));

        return to_route('storefront-delivery.index');
    }

    public function saveMapping(Request $request, WebDeliveryArea $area): RedirectResponse
    {
        $this->authorizeManage();
        $business = $this->business();
        $area = WebDeliveryArea::query()->where('business_id', $business->id)->findOrFail($area->id);
        $data = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('business_id', $business->id)],
            'delivery_fee_override' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999999999.99'],
            'selection_priority' => ['required', 'integer', 'between:0,1000000'],
            'is_active' => ['required', 'boolean'],
        ]);
        WebLocationDeliveryArea::query()->updateOrCreate(
            ['business_id' => $business->id, 'delivery_area_id' => $area->id, 'location_id' => $data['location_id']],
            ['delivery_fee_override' => $data['delivery_fee_override'] ?? null,
                'selection_priority' => $data['selection_priority'], 'is_active' => $data['is_active']],
        );

        return to_route('storefront-delivery.index');
    }

    public function removeMapping(WebDeliveryArea $area, WebLocationDeliveryArea $mapping): RedirectResponse
    {
        $this->authorizeManage();
        $business = $this->business();
        $area = WebDeliveryArea::query()->where('business_id', $business->id)->findOrFail($area->id);
        $mapping = $area->mappings()->where('business_id', $business->id)->findOrFail($mapping->id);
        // Keep the configuration row for audit and offer-history references.
        $mapping->update(['is_active' => false]);

        return to_route('storefront-delivery.index');
    }

    /** @return array<string, mixed> */
    private function areaData(Request $request, Business $business, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('web_delivery_areas', 'code')->where('business_id', $business->id)->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_delivery_fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999999999.99'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function authorizeManage(): void
    {
        Gate::authorize(PermissionName::StorefrontManageDelivery->value);
    }

    private function business(): Business
    {
        return Business::query()->where('setup_key', 'initial')->sole();
    }
}
