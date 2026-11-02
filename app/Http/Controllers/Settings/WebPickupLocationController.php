<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Location;
use App\Models\WebPickupLocation;
use App\PermissionName;
use App\Services\LocationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebPickupLocationController extends Controller
{
    public function index(Request $request, LocationAccessService $access): Response
    {
        Gate::authorize(PermissionName::StorefrontManagePickupLocations->value);
        $business = $access->business();
        $mappings = WebPickupLocation::query()->where('business_id', $business->id)->get()->keyBy('location_id');
        $locations = $access->accessibleQuery($request->user())->orderBy('name')->get();

        return Inertia::render('settings/StorefrontPickup', [
            'locations' => $locations->map(function (Location $location) use ($mappings): array {
                $pickup = $mappings->get($location->id);

                return ['id' => $location->id, 'name' => $location->name, 'type' => $location->type->value,
                    'status' => $location->status->value, 'address' => $location->address, 'city' => $location->city,
                    'public_code' => $pickup?->public_code, 'display_name' => $pickup?->display_name,
                    'selection_priority' => $pickup === null ? 100 : $pickup->selection_priority,
                    'is_active' => $pickup === null ? false : $pickup->is_active];
            })->all(),
        ]);
    }

    public function update(Request $request, Location $location, LocationAccessService $access): RedirectResponse
    {
        Gate::authorize(PermissionName::StorefrontManagePickupLocations->value);
        $business = $access->business();
        $location = $access->accessibleQuery($request->user())->whereKey($location->id)->firstOrFail();
        $existing = WebPickupLocation::query()->where('business_id', $business->id)
            ->where('location_id', $location->id)->first();
        $data = $request->validate([
            'public_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+(?:[-_][A-Za-z0-9]+)*$/',
                Rule::unique('web_pickup_locations', 'public_code')->where('business_id', $business->id)->ignore($existing?->id)],
            'display_name' => ['nullable', 'string', 'max:150'],
            'selection_priority' => ['required', 'integer', 'between:0,1000000'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($data['is_active'] && ($location->type->value !== 'shop' || $location->status->value !== 'active')) {
            throw ValidationException::withMessages(['is_active' => 'Only an active shop can be enabled for pickup.']);
        }
        $data['display_name'] = trim((string) ($data['display_name'] ?? '')) ?: null;

        DB::transaction(function () use ($business, $location, $data): void {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $current = Location::query()->where('business_id', $business->id)->whereKey($location->id)->lockForUpdate()->firstOrFail();
            if ($data['is_active'] && ($current->type->value !== 'shop' || $current->status->value !== 'active')) {
                throw ValidationException::withMessages(['is_active' => 'Only an active shop can be enabled for pickup.']);
            }
            WebPickupLocation::query()->updateOrCreate(
                ['business_id' => $business->id, 'location_id' => $current->id],
                ['public_code' => $data['public_code'], 'display_name' => $data['display_name'],
                    'selection_priority' => $data['selection_priority'], 'is_active' => $data['is_active']],
            );
        }, 3);

        return to_route('storefront-pickup.index');
    }
}
