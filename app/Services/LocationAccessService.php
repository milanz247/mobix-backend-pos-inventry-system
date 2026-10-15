<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\SystemRole;
use Illuminate\Database\Eloquent\Builder;

class LocationAccessService
{
    public function business(): Business
    {
        return Business::query()->where('setup_key', 'initial')->sole();
    }

    /** @return Builder<Location> */
    public function accessibleQuery(User $user): Builder
    {
        $business = $this->business();
        $query = Location::query()->whereBelongsTo($business);

        if ($this->hasAllLocationAccess($user, $business)) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->id));
    }

    public function canAccess(User $user, Location $location): bool
    {
        $business = $this->business();

        if ($location->business_id !== $business->id) {
            return false;
        }

        return $this->hasAllLocationAccess($user, $business)
            || $location->users()->whereKey($user->id)->exists();
    }

    public function hasAllLocationAccess(User $user, Business $business): bool
    {
        return $business->owner_id === $user->id
            || $user->hasRole(SystemRole::Owner->value);
    }
}
