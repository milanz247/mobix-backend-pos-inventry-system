<?php

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\SystemRole;
use App\UserStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function managementOwner(): User
{
    $owner = User::factory()->create();
    Business::factory()->for($owner, 'owner')->create();
    test()->seed(RolePermissionSeeder::class);

    return $owner->refresh();
}

test('authorized owner can list and create users with roles', function () {
    $owner = managementOwner();
    $cashier = Role::findByName(SystemRole::Cashier->value);

    $this->actingAs($owner)->get(route('users.index'))->assertOk();
    $this->actingAs($owner)->post(route('users.store'), [
        'name' => 'New Cashier', 'email' => 'cashier@example.com', 'phone' => '0712345678',
        'password' => 'password', 'password_confirmation' => 'password', 'status' => 'active',
        'role_ids' => [$cashier->id],
    ])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'cashier@example.com')->firstOrFail();
    expect($user->hasRole(SystemRole::Cashier->value))->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('unauthorized user cannot access user management', function () {
    managementOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
});

test('user validation rejects duplicate emails', function () {
    $owner = managementOwner();
    $existing = User::factory()->create();
    $cashier = Role::findByName(SystemRole::Cashier->value);

    $this->actingAs($owner)->post(route('users.store'), [
        'name' => 'Duplicate', 'email' => $existing->email, 'password' => 'password',
        'password_confirmation' => 'different', 'status' => 'active', 'role_ids' => [$cashier->id],
    ])->assertSessionHasErrors(['email', 'password']);
});

test('admin cannot grant the owner role', function () {
    managementOwner();
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Admin->value);
    $ownerRole = Role::findByName(SystemRole::Owner->value);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Escalated', 'email' => 'escalated@example.com', 'password' => 'password',
        'password_confirmation' => 'password', 'status' => 'active', 'role_ids' => [$ownerRole->id],
    ])->assertSessionHasErrors('role_ids');

    $this->assertDatabaseMissing('users', ['email' => 'escalated@example.com']);
});

test('registered owner cannot be deactivated or deleted', function () {
    $owner = managementOwner();

    $this->actingAs($owner)->patch(route('users.status.update', $owner), ['status' => UserStatus::Inactive->value])->assertSessionHasErrors('status');
    $this->actingAs($owner)->delete(route('users.destroy', $owner))->assertSessionHasErrors('user');

    expect($owner->refresh()->status)->toBe(UserStatus::Active);
});

test('deleting a managed user also removes their private profile photo', function () {
    Storage::fake(config('filesystems.default'));
    $owner = managementOwner();
    $user = User::factory()->create();
    $photoPath = "profile-photos/{$user->id}/avatar.jpg";
    Storage::put($photoPath, 'profile photo');
    $user->update(['profile_photo_path' => $photoPath]);

    $this->actingAs($owner)
        ->delete(route('users.destroy', $user))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $this->assertModelMissing($user);
    Storage::disk(config('filesystems.default'))->assertMissing($photoPath);
});
