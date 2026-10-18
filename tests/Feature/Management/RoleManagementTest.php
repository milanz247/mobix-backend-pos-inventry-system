<?php

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\SystemRole;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function roleManagementOwner(): User
{
    $owner = User::factory()->create();
    Business::factory()->for($owner, 'owner')->create();
    test()->seed(RolePermissionSeeder::class);

    return $owner->refresh();
}

test('owner can create and update a role with permissions', function () {
    $owner = roleManagementOwner();
    $permission = Permission::findByName(PermissionName::DashboardView->value);

    $this->actingAs($owner)->post(route('roles.store'), [
        'name' => 'Supervisor', 'description' => 'Supervises staff', 'permission_ids' => [$permission->id],
    ])->assertRedirect(route('roles.index'))->assertSessionHasNoErrors();

    $role = Role::findByName('Supervisor');
    $this->actingAs($owner)->put(route('roles.update', $role), [
        'name' => 'Floor Supervisor', 'description' => 'Updated', 'permission_ids' => [$permission->id],
    ])->assertRedirect(route('roles.index'))->assertSessionHasNoErrors();

    expect($role->refresh()->name)->toBe('Floor Supervisor')->and($role->hasPermissionTo($permission))->toBeTrue();
});

test('roles page includes permissions for the create role dialog', function () {
    $owner = roleManagementOwner();

    $this->actingAs($owner)
        ->get(route('roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('management/roles/Index')
            ->has('roles')
            ->has('permissionGroups')
            ->etc());
});

test('system and assigned roles cannot be deleted', function () {
    $owner = roleManagementOwner();
    $this->actingAs($owner)->delete(route('roles.destroy', Role::findByName(SystemRole::Admin->value)))->assertSessionHasErrors('role');

    $custom = Role::create(['name' => 'Assigned', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($custom);
    $this->actingAs($owner)->delete(route('roles.destroy', $custom))->assertSessionHasErrors('role');
});

test('user without role permissions cannot access role management', function () {
    roleManagementOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
});
