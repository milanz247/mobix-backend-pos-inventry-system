<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\SystemRole;
use App\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $attributes): User {
            $roles = $this->roles($attributes['role_ids']);
            $this->ensureRolesMayBeAssigned($actor, $roles);

            $user = User::query()->create(Arr::except($attributes, ['role_ids', 'password_confirmation']));
            $user->syncRoles($roles);

            return $user;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, User $managedUser, array $attributes): User
    {
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

            $managedUser->update(Arr::except($attributes, ['role_ids', 'password_confirmation']));
            $managedUser->syncRoles($roles);

            return $managedUser;
        });
    }

    public function updateStatus(User $actor, User $managedUser, UserStatus $status): void
    {
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

    private function ensureUserMayBeManaged(User $actor, User $managedUser): void
    {
        if ($this->isOwner($managedUser) && ! $actor->hasRole(SystemRole::Owner->value)) {
            $this->fail('user', __('Only the Owner can manage an Owner account.'));
        }
    }

    /** @param Collection<int, Role> $roles */
    private function ensureRolesMayBeAssigned(User $actor, Collection $roles): void
    {
        if ($roles->contains('name', SystemRole::Owner->value) && ! $actor->hasRole(SystemRole::Owner->value)) {
            $this->fail('role_ids', __('Only the Owner can assign the Owner role.'));
        }
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
        return $user->hasRole(SystemRole::Owner->value);
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
