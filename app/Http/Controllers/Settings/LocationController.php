<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreLocationRequest;
use App\Http\Requests\Settings\UpdateLocationRequest;
use App\LocationStatus;
use App\LocationType;
use App\Models\Location;
use App\Services\LocationAccessService;
use App\Services\LocationAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, LocationAccessService $locationAccess): Response
    {
        Gate::authorize('viewAny', Location::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(LocationStatus::class)],
        ]);

        $locations = $locationAccess->accessibleQuery($request->user())
            ->withCount('users')
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('settings/locations/Index', [
            'locations' => $locations,
            'filters' => $filters,
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', Location::class);

        return Inertia::render('settings/locations/Create', [
            'types' => $this->types(),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLocationRequest $request, LocationAdministrationService $service): RedirectResponse
    {
        $location = $service->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Location created.')]);

        return to_route('locations.show', $location);
    }

    /**
     * Display the specified resource.
     */
    public function show(Location $location): Response
    {
        Gate::authorize('view', $location);
        $location->loadCount('users');

        return Inertia::render('settings/locations/Show', [
            'location' => $location,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Location $location): Response
    {
        Gate::authorize('update', $location);

        return Inertia::render('settings/locations/Edit', [
            'location' => $location,
            'types' => $this->types(),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLocationRequest $request, Location $location, LocationAdministrationService $service): RedirectResponse
    {
        $service->update($location, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Location updated.')]);

        return to_route('locations.show', $location);
    }

    /**
     * Remove the specified resource from storage.
     */
    /** @return list<array{value: string, label: string}> */
    private function types(): array
    {
        return array_map(fn (LocationType $type): array => ['value' => $type->value, 'label' => $type->label()], LocationType::cases());
    }

    /** @return list<array{value: string, label: string}> */
    private function statuses(): array
    {
        return array_map(fn (LocationStatus $status): array => ['value' => $status->value, 'label' => $status->label()], LocationStatus::cases());
    }
}
