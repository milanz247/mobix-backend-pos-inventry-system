<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLocationStatusRequest;
use App\LocationStatus;
use App\Models\Location;
use App\Services\LocationAdministrationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LocationStatusController extends Controller
{
    public function update(UpdateLocationStatusRequest $request, Location $location, LocationAdministrationService $service): RedirectResponse
    {
        $service->updateStatus($location, LocationStatus::from($request->validated('status')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Location status updated.')]);

        return back();
    }
}
