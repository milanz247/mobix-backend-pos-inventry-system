<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLocationUsersRequest;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationAccessService;
use App\Services\LocationAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LocationUserController extends Controller
{
    public function edit(Location $location, LocationAccessService $locationAccess): Response
    {
        Gate::authorize('assignUsers', $location);
        $business = $locationAccess->business();
        $location->load('users:id');

        return Inertia::render('settings/locations/Users', [
            'location' => $location,
            'users' => User::query()
                ->with('roles:id,name')
                ->whereDoesntHave('ownedBusiness', fn ($query) => $query->whereKeyNot($business->id))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'status']),
            'assignedUserIds' => $location->users->modelKeys(),
            'ownerId' => $business->owner_id,
        ]);
    }

    public function update(UpdateLocationUsersRequest $request, Location $location, LocationAdministrationService $service): RedirectResponse
    {
        $service->syncUsers($location, $request->validated('user_ids'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Location access updated.')]);

        return back();
    }
}
