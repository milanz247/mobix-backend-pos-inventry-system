<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\SystemRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class RoleAdministrationService
{
    public function __construct(private readonly LocationAccessService $locations) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Role
    {
        abort_unless($actor->can('roles.create'), 403);

        return DB::transaction(function () use ($actor, $attributes): Role {
            $permissions = $this->permissions($attributes['permission_ids']);
            $this->ensurePermissionsMayBeAssigned($actor, $permissions);

            $role = Role::query()->create([
                'name' => $attributes['name'],
                'guard_name' => 'web',
            ]);
            $role->forceFill(['description' => $attributes['description'] ?? null, 'is_system' => false])->save();
            $role->syncPermissions($permissions);

            return $role;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Role $role, array $attributes): Role
    {
        abort_unless($actor->can('roles.update'), 403);

        return DB::transaction(function () use ($actor, $role, $attributes): Role {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($role->name === SystemRole::Owner->value) {
                $this->fail(__('The protected Owner role cannot be changed.'));
            }

            $permissions = $this->permissions($attributes['permission_ids']);
            $this->ensurePermissionsMayBeAssigned($actor, $permissions);
            $this->ensurePermissionsMayBeAssigned($actor, $role->permissions);

            if ($actor->hasRole($role->name) && $permissions->pluck('id')->diff($role->permissions->pluck('id'))->isNotEmpty()) {
                $this->fail(__('You cannot add permissions to your own role.'));
            }
            $role->forceFill([
                'name' => $role->is_system ? $role->name : $attributes['name'],
                'description' => $attributes['description'] ?? null,
            ])->save();
            $role->syncPermissions($permissions);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $role = Role::query()->withCount('users')->lockForUpdate()->findOrFail($role->id);

            if ($role->is_system) {
                $this->fail(__('System roles cannot be deleted.'));
            }

            if ($role->users_count > 0) {
                $this->fail(__('Remove this role from all users before deleting it.'));
            }

            $role->delete();
        });
    }

    /**
     * @param  array<int, mixed>  $permissionIds
     * @return Collection<int, Permission>
     */
    private function permissions(array $permissionIds): Collection
    {
        $permissions = Permission::query()->where('guard_name', 'web')->whereKey($permissionIds)->get();
        $names = $permissions->pluck('name')->all();
        do {
            $before = count($names);
            foreach ($names as $name) {
                foreach (PermissionName::tryFrom($name)?->dependencies() ?? [] as $dependency) {
                    if (! in_array($dependency, $names, true)) {
                        $names[] = $dependency;
                    }
                }
            }
        } while (count($names) !== $before);

        return Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->get();
    }

    /** @param Collection<int, Permission> $permissions */
    private function ensurePermissionsMayBeAssigned(User $actor, Collection $permissions): void
    {
        if ($this->locations->hasAllLocationAccess($actor, $this->locations->business())) {
            return;
        }

        $unavailable = $permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'));

        if ($unavailable->isNotEmpty()) {
            $this->fail(__('You cannot assign permissions that you do not have.'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['role' => $message]);
    }
}
