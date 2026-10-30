<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreRoleRequest;
use App\Http\Requests\Management\UpdateRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\Services\RoleAdministrationService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('management/roles/Index', [
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('name')->get(),
            'permissionGroups' => $request->user()->can(PermissionName::RolesCreate->value)
                ? $this->permissionGroups($request->user())
                : [],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('management/roles/Create', [
            'permissionGroups' => $this->permissionGroups($request->user()),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoleRequest $request, RoleAdministrationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.index');
    }

    /**
     * Display the specified resource.
     */
    public function edit(Request $request, Role $role): Response
    {
        $role->load('permissions:id,name');

        return Inertia::render('management/roles/Edit', [
            'role' => $role,
            'permissionGroups' => $this->permissionGroups($request->user()),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role, RoleAdministrationService $service): RedirectResponse
    {
        $service->update($request->user(), $role, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role, RoleAdministrationService $service): RedirectResponse
    {
        $service->delete($role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function permissionGroups(User $actor): array
    {
        return app(PermissionCatalog::class)->groups($actor);
    }
}
