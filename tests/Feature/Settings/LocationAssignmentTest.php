<?php

use App\LocationStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

function locationAssignmentContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $location = Location::factory()->for($business)->create(['code' => 'MAIN', 'is_main' => true]);
    $location->users()->attach($owner);
    test()->seed(RolePermissionSeeder::class);

    return [$owner->refresh(), $business, $location];
}

test('owner can assign multiple users while retaining owner access', function () {
    [$owner, , $location] = locationAssignmentContext();
    $users = User::factory()->count(2)->create();

    $this->actingAs($owner)
        ->put(route('locations.users.update', $location), ['user_ids' => $users->modelKeys()])
        ->assertSessionHasNoErrors();

    expect($location->users()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$owner->id, ...$users->modelKeys()])->sort()->values()->all());
});

test('one user can be assigned to multiple locations', function () {
    [$owner, $business, $firstLocation] = locationAssignmentContext();
    $secondLocation = Location::factory()->for($business)->create(['code' => 'SECOND']);
    $user = User::factory()->create();

    $this->actingAs($owner)->put(route('locations.users.update', $firstLocation), ['user_ids' => [$user->id]])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put(route('locations.users.update', $secondLocation), ['user_ids' => [$user->id]])->assertSessionHasNoErrors();

    expect($user->locations()->whereKey($firstLocation->id)->exists())->toBeTrue()
        ->and($user->locations()->whereKey($secondLocation->id)->exists())->toBeTrue();
});

test('users cannot be assigned to an inactive location', function () {
    [$owner, , $location] = locationAssignmentContext();
    $location->update(['status' => LocationStatus::Inactive]);
    $user = User::factory()->create();

    $this->actingAs($owner)
        ->put(route('locations.users.update', $location), ['user_ids' => [$user->id]])
        ->assertSessionHasErrors('user_ids');

    expect($location->users()->whereKey($user->id)->exists())->toBeFalse();
});

test('owner from another business cannot be assigned to a location', function () {
    [$owner, , $location] = locationAssignmentContext();
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'other-business']);

    $this->actingAs($owner)
        ->put(route('locations.users.update', $location), ['user_ids' => [$otherOwner->id]])
        ->assertSessionHasErrors('user_ids');

    expect($location->users()->whereKey($otherOwner->id)->exists())->toBeFalse();
});
