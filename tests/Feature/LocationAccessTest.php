<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\PermissionName;
use App\SystemRole;
use Database\Seeders\RolePermissionSeeder;

function locationAccessContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $first = Location::factory()->for($business)->create(['code' => 'MAIN', 'is_main' => true]);
    $second = Location::factory()->for($business)->create(['code' => 'WAREHOUSE']);
    $first->users()->attach($owner);
    test()->seed(RolePermissionSeeder::class);

    return [$owner->refresh(), $business, $first, $second];
}

test('owner can access every location without individual assignments', function () {
    [$owner, , $first, $second] = locationAccessContext();

    $this->actingAs($owner)->get(route('locations.show', $first))->assertOk();
    $this->actingAs($owner)->get(route('locations.show', $second))->assertOk();
});

test('ordinary user can access only assigned locations', function () {
    [, , $assigned, $unassigned] = locationAccessContext();
    $user = User::factory()->create();
    $user->givePermissionTo(PermissionName::LocationsView->value);
    $assigned->users()->attach($user);

    $this->actingAs($user)->get(route('locations.show', $assigned))->assertOk();
    $this->actingAs($user)->get(route('locations.show', $unassigned))->assertForbidden();

    $response = $this->actingAs($user)->get(route('locations.index'));
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->has('locations.data', 1)
        ->where('locations.data.0.id', $assigned->id));
});

test('admin role does not grant access to an unassigned location', function () {
    [, , $assigned, $unassigned] = locationAccessContext();
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Admin->value);
    $assigned->users()->attach($admin);

    $this->actingAs($admin)->get(route('locations.show', $assigned))->assertOk();
    $this->actingAs($admin)->get(route('locations.show', $unassigned))->assertForbidden();
});

test('direct access to a location from another business is denied', function () {
    [$owner] = locationAccessContext();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'other-business']);
    $otherLocation = Location::factory()->for($otherBusiness)->create(['code' => 'OTHER-MAIN', 'is_main' => true]);

    $this->actingAs($owner)->get(route('locations.show', $otherLocation))->assertForbidden();
    $this->actingAs($owner)->put(route('locations.update', $otherLocation), [])->assertForbidden();
});
