<?php

use App\Models\User;
use App\PermissionName;
use Spatie\Permission\Models\Permission;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authorized users can visit the dashboard', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate(PermissionName::DashboardView->value, 'web'));
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
