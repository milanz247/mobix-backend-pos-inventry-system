<?php

namespace App\Services;

use App\LocationStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationAdministrationService
{
    public function __construct(
        private readonly LocationAccessService $locationAccess,
        private readonly WebReservationService $reservations,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Location
    {
        return DB::transaction(function () use ($actor, $attributes): Location {
            $business = $this->locationAccess->business();
            $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            $location = $business->locations()->create([
                ...Arr::only($attributes, ['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'status']),
                'is_main' => false,
            ]);
            $location->users()->syncWithoutDetaching([$business->owner_id, $actor->id]);

            return $location;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Location $location, array $attributes): Location
    {
        return DB::transaction(function () use ($location, $attributes): Location {
            $business = $this->locationAccess->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $this->ensureCurrentBusiness($location);

            if ($location->is_main && $attributes['status'] === LocationStatus::Inactive->value) {
                $this->fail('status', __('The main location cannot be deactivated.'));
            }
            $this->assertNoActiveReservationsOnDeactivation($location, $attributes['status'] ?? null);

            $location->update(Arr::only($attributes, ['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'status']));

            return $location;
        });
    }

    public function updateStatus(Location $location, LocationStatus $status): void
    {
        DB::transaction(function () use ($location, $status): void {
            $business = $this->locationAccess->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $this->ensureCurrentBusiness($location);

            if ($location->is_main && $status === LocationStatus::Inactive) {
                $this->fail('status', __('The main location cannot be deactivated.'));
            }
            $this->assertNoActiveReservationsOnDeactivation($location, $status->value);

            $location->update(['status' => $status]);
        });
    }

    /** @param list<int> $userIds */
    public function syncUsers(Location $location, array $userIds, User $actor): void
    {
        abort_unless($actor->can('assignUsers', $location), 403);
        DB::transaction(function () use ($location, $userIds, $actor): void {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);
            $business = $this->ensureCurrentBusiness($location);

            if ($location->status !== LocationStatus::Active) {
                $this->fail('user_ids', __('Users can only be assigned to active locations.'));
            }

            $retainedIds = $location->users()->whereNotIn('users.id', $this->locationAccess->manageableUsersQuery($actor)->select('users.id'))->pluck('users.id')->all();
            $userIds = array_values(array_unique([...$userIds, ...$retainedIds, $business->owner_id]));
            $users = User::query()->whereKey($userIds)->with('ownedBusiness:id,owner_id,setup_key')->get();

            if ($users->count() !== count($userIds)) {
                $this->fail('user_ids', __('One or more selected users are invalid.'));
            }

            $hasUnrelatedOwner = $users->contains(fn (User $user): bool => $user->ownedBusiness !== null
                && $user->ownedBusiness->id !== $business->id);

            if ($hasUnrelatedOwner) {
                $this->fail('user_ids', __('Users from another business cannot be assigned.'));
            }

            $currentlyAssigned = $location->users()->pluck('users.id')->all();
            $changedIds = array_values(array_unique([...array_diff($currentlyAssigned, $userIds), ...array_diff($userIds, $currentlyAssigned)]));
            if (in_array($actor->id, $changedIds, true) && ! $this->locationAccess->hasAllLocationAccess($actor, $business)) {
                $this->fail('user_ids', __('You cannot change your own location assignments.'));
            }
            foreach (User::query()->whereKey($changedIds)->get() as $user) {
                if (! $this->locationAccess->manageableUsersQuery($actor)->whereKey($user->id)->exists()) {
                    $this->fail('user_ids', __('You cannot change assignments for users outside your authorized locations.'));
                }
            }

            $location->users()->sync($userIds);
        });
    }

    private function ensureCurrentBusiness(Location $location): Business
    {
        $business = $this->locationAccess->business();

        abort_unless($location->business_id === $business->id, 404);

        return $business;
    }

    private function assertNoActiveReservationsOnDeactivation(Location $location, ?string $status): void
    {
        if ($status === LocationStatus::Inactive->value
            && $this->reservations->hasActiveAtLocation($location->business_id, $location->id)) {
            $this->fail('status', __('This location has active online stock reservations.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
