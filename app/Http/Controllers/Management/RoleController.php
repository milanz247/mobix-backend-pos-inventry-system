<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreRoleRequest;
use App\Http\Requests\Management\UpdateRoleRequest;
use App\Models\Role;
use App\PermissionName;
use App\Services\RoleAdministrationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('management/roles/Index', [
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('management/roles/Create', [
            'permissionGroups' => $this->permissionGroups(),
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
    public function edit(Role $role): Response
    {
        $role->load('permissions:id,name');

        return Inertia::render('management/roles/Edit', [
            'role' => $role,
            'permissionGroups' => $this->permissionGroups(),
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

    /** @return array<string, array<int, array{id: int, name: string, label: string}>> */
    private function permissionGroups(): array
    {
        $groups = [];

        foreach (Permission::query()->orderBy('name')->get() as $permission) {
            $permissionName = PermissionName::from($permission->name);
            $permissionId = $permission->getKey();

            if (! is_int($permissionId)) {
                throw new \UnexpectedValueException('Permission primary keys must be integers.');
            }

            $groups[$permissionName->module()][] = [
                'id' => $permissionId,
                'name' => $permission->name,
                'label' => $permissionName->label(),
            ];
        }

        return $groups;
    }
}
