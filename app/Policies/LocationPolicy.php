<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;
use App\PermissionName;
use App\Services\LocationAccessService;

class LocationPolicy
{
    public function __construct(private readonly LocationAccessService $locationAccess) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::LocationsView->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Location $location): bool
    {
        return $user->can(PermissionName::LocationsView->value)
            && $this->locationAccess->canAccess($user, $location);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(PermissionName::LocationsCreate->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Location $location): bool
    {
        return $user->can(PermissionName::LocationsUpdate->value)
            && $this->locationAccess->canAccess($user, $location);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Location $location): bool
    {
        return false;
    }

    public function assignUsers(User $user, Location $location): bool
    {
        return $user->can(PermissionName::LocationsAssignUsers->value)
            && $this->locationAccess->canAccess($user, $location);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Location $location): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Location $location): bool
    {
        return false;
    }
}
