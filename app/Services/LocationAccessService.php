<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\SystemRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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

    /** @return Builder<User> */
    public function manageableUsersQuery(User $actor): Builder
    {
        $business = $this->business();
        $query = User::query()
            ->whereDoesntHave('ownedBusiness', fn (Builder $query): Builder => $query->whereKeyNot($business->id))
            ->whereDoesntHave('locations', fn (Builder $query): Builder => $query->where('business_id', '!=', $business->id));

        if (! $this->hasAllLocationAccess($actor, $business)) {
            $query->whereDoesntHave('locations', fn (Builder $query): Builder => $query
                ->whereNotIn('locations.id', $this->accessibleQuery($actor)->select('locations.id')));
        }

        return $query;
    }

    public function selectedLocationId(Request $request): ?int
    {
        $id = $request->session()->get('active_location_id');

        return is_int($id) && $this->accessibleQuery($request->user())->where('status', 'active')->whereKey($id)->exists()
            ? $id
            : null;
    }
}
