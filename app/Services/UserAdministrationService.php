<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Role;
use App\Models\ShiftEvent;
use App\Models\User;
use App\PermissionName;
use App\SystemRole;
use App\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(private readonly LocationAccessService $locations) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): User
    {
        abort_unless($actor->can('users.create') && $actor->can('users.assign-roles'), 403);

        return DB::transaction(function () use ($actor, $attributes): User {
            $roles = $this->roles($attributes['role_ids']);
            $this->ensureRolesMayBeAssigned($actor, $roles);

            $user = User::query()->create(Arr::except($attributes, ['role_ids', 'location_ids', 'password_confirmation']));
            $user->syncRoles($roles);
            $this->syncLocations($actor, $user, $attributes);

            return $user;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, User $managedUser, array $attributes): User
    {
        abort_unless($actor->can('users.update') && $actor->can('users.assign-roles'), 403);

        return DB::transaction(function () use ($actor, $managedUser, $attributes): User {
            $managedUser = User::query()->lockForUpdate()->findOrFail($managedUser->id);
            $roles = $this->roles($attributes['role_ids']);
            $this->ensureUserMayBeManaged($actor, $managedUser);
            $this->ensureRolesMayBeAssigned($actor, $roles);

            if ($actor->is($managedUser) && $this->roleIdsDiffer($managedUser, $roles)) {
                $this->fail('role_ids', __('You cannot change your own roles.'));
            }

            if ($this->isOwner($managedUser) && ! $roles->contains('name', SystemRole::Owner->value)) {
                $this->ensureOwnerMayBeChanged($managedUser);
            }

            if ($attributes['status'] === UserStatus::Inactive->value) {
                $this->ensureMayBeDeactivated($actor, $managedUser);
            }

            if (blank($attributes['password'] ?? null)) {
                unset($attributes['password']);
            }

            $this->syncLocations($actor, $managedUser, $attributes);
            $managedUser->update(Arr::except($attributes, ['role_ids', 'location_ids', 'password_confirmation']));
            $managedUser->syncRoles($roles);

            return $managedUser;
        });
    }

    public function updateStatus(User $actor, User $managedUser, UserStatus $status): void
    {
        abort_unless($actor->can('users.update'), 403);
        DB::transaction(function () use ($actor, $managedUser, $status): void {
            $managedUser = User::query()->lockForUpdate()->findOrFail($managedUser->id);
            $this->ensureUserMayBeManaged($actor, $managedUser);

            if ($status === UserStatus::Inactive) {
                $this->ensureMayBeDeactivated($actor, $managedUser);
            }

            $managedUser->update(['status' => $status]);
        });
    }

    public function delete(User $actor, User $managedUser): void
    {
        abort_unless($actor->can('users.delete'), 403);
        $profilePhotoPath = DB::transaction(function () use ($actor, $managedUser): ?string {
            $managedUser = User::query()->lockForUpdate()->findOrFail($managedUser->id);
            $this->ensureUserMayBeManaged($actor, $managedUser);

            if ($actor->is($managedUser)) {
                $this->fail('user', __('You cannot delete your own account.'));
            }

            if ($this->isOwner($managedUser)) {
                $this->ensureOwnerMayBeChanged($managedUser);
            }

            $profilePhotoPath = $managedUser->profile_photo_path;
            if (CashierShift::query()->where('cashier_id', $managedUser->id)->exists()
                || ShiftEvent::query()->where('actor_id', $managedUser->id)->exists()) {
                $this->fail('user', __('Users with cashier shift history cannot be deleted. Deactivate the account instead.'));
            }
            $managedUser->delete();

            return $profilePhotoPath;
        });

        if ($profilePhotoPath !== null) {
            Storage::delete($profilePhotoPath);
        }
    }

    /**
     * @param  array<int, mixed>  $roleIds
     * @return Collection<int, Role>
     */
    private function roles(array $roleIds): Collection
    {
        return Role::query()->where('guard_name', 'web')->whereKey($roleIds)->get();
    }

    public function ensureUserMayBeManaged(User $actor, User $managedUser): void
    {
        abort_unless($this->locations->manageableUsersQuery($actor)->whereKey($managedUser->id)->exists(), 404);
        $isOwner = $this->locations->hasAllLocationAccess($actor, $this->locations->business());
        if (($this->isOwner($managedUser) || $managedUser->ownedBusiness()->exists()) && ! $isOwner) {
            $this->fail('user', __('Only the Owner can manage an Owner account.'));
        }

        if (! $this->canManagePrivileges($actor, $managedUser)) {
            $this->fail('user', __('You cannot manage an account with permissions you do not have.'));
        }
    }

    public function canManagePrivileges(User $actor, User $managedUser): bool
    {
        return $this->locations->hasAllLocationAccess($actor, $this->locations->business())
            || (! $this->isOwner($managedUser) && $managedUser->getAllPermissions()->every(fn ($permission): bool => $actor->can($permission->name)));
    }

    /** @param Collection<int, Role> $roles */
    private function ensureRolesMayBeAssigned(User $actor, Collection $roles): void
    {
        $isOwner = $this->locations->hasAllLocationAccess($actor, $this->locations->business());
        if ($roles->contains('name', SystemRole::Owner->value) && ! $isOwner) {
            $this->fail('role_ids', __('Only the Owner can assign the Owner role.'));
        }

        if (! $isOwner && $roles->contains(fn (Role $role): bool => $role->permissions->contains(fn ($permission): bool => ! $actor->can($permission->name)))) {
            $this->fail('role_ids', __('You cannot assign roles with permissions you do not have.'));
        }
    }

    /** @param array<string, mixed> $attributes */
    private function syncLocations(User $actor, User $user, array $attributes): void
    {
        if (! array_key_exists('location_ids', $attributes)) {
            return;
        }
        abort_unless($actor->can(PermissionName::LocationsAssignUsers->value), 403);
        $ids = array_map('intval', $attributes['location_ids']);
        $retainedIds = $user->locations()->pluck('locations.id')->all();
        if ($this->locations->accessibleQuery($actor)->where(fn ($query) => $query->where('status', 'active')->orWhereIn('id', $retainedIds))->whereKey($ids)->count() !== count(array_unique($ids))) {
            $this->fail('location_ids', __('Choose only active locations you are authorized to manage.'));
        }
        if ($actor->is($user) && $user->locations()->pluck('locations.id')->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
            $this->fail('location_ids', __('You cannot change your own location assignments.'));
        }
        if ($this->isOwner($user) || $user->ownedBusiness()->exists()) {
            return;
        }
        $user->locations()->sync($ids);
        $user->unsetRelation('locations');
    }

    private function ensureMayBeDeactivated(User $actor, User $managedUser): void
    {
        if ($actor->is($managedUser)) {
            $this->fail('status', __('You cannot deactivate your own account.'));
        }

        if ($this->isOwner($managedUser)) {
            $this->ensureOwnerMayBeChanged($managedUser);
        }
    }

    private function ensureOwnerMayBeChanged(User $managedUser): void
    {
        if (Business::query()->where('owner_id', $managedUser->id)->exists()) {
            $this->fail('user', __('The registered business Owner cannot be deleted, deactivated, or demoted.'));
        }

        $otherActiveOwners = User::query()
            ->whereKeyNot($managedUser->id)
            ->where('status', UserStatus::Active)
            ->role(SystemRole::Owner->value)
            ->lockForUpdate()
            ->exists();

        if (! $otherActiveOwners) {
            $this->fail('user', __('The last active Owner cannot be deleted, deactivated, or demoted.'));
        }
    }

    private function isOwner(User $user): bool
    {
        return $user->hasRole(SystemRole::Owner->value)
            || $user->ownedBusiness?->setup_key === 'initial';
    }

    /** @param Collection<int, Role> $roles */
    private function roleIdsDiffer(User $user, Collection $roles): bool
    {
        return $user->roles()->pluck('roles.id')->sort()->values()->all()
            !== $roles->pluck('id')->sort()->values()->all();
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
