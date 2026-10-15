<?php

use App\Models\User;
use App\PermissionName;
use Database\Seeders\RolePermissionSeeder;

test('permission page requires the permissions view permission', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('permissions.index'))->assertForbidden();

    $user->givePermissionTo(PermissionName::PermissionsView->value);
    $this->actingAs($user)->get(route('permissions.index'))->assertOk();
});
