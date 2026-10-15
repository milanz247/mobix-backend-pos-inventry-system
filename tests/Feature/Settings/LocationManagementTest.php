<?php

use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

function locationManagementOwner(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $mainLocation = Location::factory()->for($business)->create([
        'name' => 'Main Shop', 'code' => 'MAIN', 'is_main' => true,
    ]);
    $mainLocation->users()->attach($owner);
    test()->seed(RolePermissionSeeder::class);

    return [$owner->refresh(), $business, $mainLocation];
}

function validLocationData(array $overrides = []): array
{
    return array_replace([
        'name' => 'Kandy Branch',
        'code' => 'kan-01',
        'type' => LocationType::Shop->value,
        'phone' => '0812345678',
        'email' => 'kandy@example.com',
        'address' => '10 Temple Street',
        'city' => 'Kandy',
        'status' => LocationStatus::Active->value,
    ], $overrides);
}

test('owner can create and update a location without supplying a business identity', function () {
    [$owner, $business] = locationManagementOwner();
    $unrelatedBusiness = Business::factory()->create(['setup_key' => 'other']);

    $response = $this->actingAs($owner)->post(route('locations.store'), validLocationData([
        'business_id' => $unrelatedBusiness->id,
        'is_main' => true,
    ]));

    $location = Location::query()->where('code', 'KAN-01')->firstOrFail();
    $response->assertRedirect(route('locations.show', $location))->assertSessionHasNoErrors();
    expect($location->business_id)->toBe($business->id)
        ->and($location->is_main)->toBeFalse()
        ->and($location->users()->whereKey($owner->id)->exists())->toBeTrue();

    $this->actingAs($owner)->put(route('locations.update', $location), validLocationData([
        'name' => 'Kandy City Branch', 'code' => 'KAN-02',
    ]))->assertRedirect(route('locations.show', $location))->assertSessionHasNoErrors();

    expect($location->refresh()->name)->toBe('Kandy City Branch')
        ->and($location->code)->toBe('KAN-02');
});

test('location codes must be unique within the business', function () {
    [$owner, $business] = locationManagementOwner();
    Location::factory()->for($business)->create(['code' => 'KANDY']);

    $this->actingAs($owner)
        ->post(route('locations.store'), validLocationData(['code' => 'kandy']))
        ->assertSessionHasErrors('code');

    expect($business->locations()->where('code', 'KANDY')->count())->toBe(1);
});

test('main location cannot be deactivated', function () {
    [$owner, , $mainLocation] = locationManagementOwner();

    $this->actingAs($owner)
        ->patch(route('locations.status.update', $mainLocation), ['status' => LocationStatus::Inactive->value])
        ->assertSessionHasErrors('status');

    expect($mainLocation->refresh()->status)->toBe(LocationStatus::Active);
});

test('user without location permissions cannot manage locations', function () {
    locationManagementOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('locations.index'))->assertForbidden();
    $this->actingAs($user)->post(route('locations.store'), validLocationData())->assertForbidden();
});
