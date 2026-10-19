<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{user: User, business: Business} */
function profileSettingsContext(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->for($user, 'owner')->create([
        'name' => 'Nalaka Mobile',
    ]);

    return ['user' => $user, 'business' => $business];
}

test('guests cannot access or update personal profile settings', function () {
    $this->get(route('profile-settings.edit'))->assertRedirect(route('login'));
    $this->put(route('profile-settings.update'), [])->assertRedirect(route('login'));
    $this->delete(route('profile-photo.destroy'))->assertRedirect(route('login'));
});

test('an authenticated user can view personal details roles and authorized locations', function () {
    ['user' => $user, 'business' => $business] = profileSettingsContext();
    $location = Location::factory()->for($business)->create([
        'name' => 'Main Shop',
        'code' => 'MAIN',
    ]);

    $this->actingAs($user)
        ->get(route('profile-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('profile.name', $user->name)
            ->where('profile.email', $user->email)
            ->where('profile.avatar', null)
            ->where('auth.user.avatar', null)
            ->has('roles')
            ->has('locations', 1)
            ->where('locations.0.id', $location->id)
            ->where('locations.0.name', 'Main Shop'));
});

test('an authenticated user can update personal details and upload a valid profile photo', function () {
    Storage::fake(config('filesystems.default'));
    ['user' => $user, 'business' => $business] = profileSettingsContext();
    $photo = UploadedFile::fake()->image('profile.jpg', 400, 400)->size(500);

    $this->actingAs($user)
        ->from(route('profile-settings.edit'))
        ->put(route('profile-settings.update'), [
            'name' => 'Updated Display Name',
            'phone' => '0771234567',
            'profile_photo' => $photo,
            'email' => 'ignored@example.com',
            'profile_photo_path' => 'unsafe/path.php',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile-settings.edit'));

    $user->refresh();

    expect($user->name)->toBe('Updated Display Name')
        ->and($user->phone)->toBe('0771234567')
        ->and($user->email)->not->toBe('ignored@example.com')
        ->and($user->profile_photo_path)->toStartWith("profile-photos/{$user->id}/")
        ->and($user->profile_photo_path)->not->toEndWith('.php')
        ->and($business->refresh()->name)->toBe('Nalaka Mobile');

    Storage::disk(config('filesystems.default'))->assertExists($user->profile_photo_path);

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

    $this->actingAs($user)
        ->get(route('profile-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.name', 'Updated Display Name')
            ->where('profile.avatar', fn (string $avatar): bool => str_contains($avatar, route('profile-photo.show', absolute: false))));

    $this->actingAs($user)
        ->get(route('profile-photo.show'))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
});

test('profile photos must be supported images no larger than two megabytes', function () {
    Storage::fake(config('filesystems.default'));
    ['user' => $user] = profileSettingsContext();

    $this->actingAs($user)
        ->put(route('profile-settings.update'), [
            'name' => $user->name,
            'phone' => $user->phone,
            'profile_photo' => UploadedFile::fake()->create('payload.php', 100, 'application/x-php'),
        ])
        ->assertSessionHasErrors('profile_photo');

    $this->actingAs($user)
        ->put(route('profile-settings.update'), [
            'name' => $user->name,
            'phone' => $user->phone,
            'profile_photo' => UploadedFile::fake()->image('large.png')->size(2049),
        ])
        ->assertSessionHasErrors('profile_photo');

    expect($user->refresh()->profile_photo_path)->toBeNull();
    Storage::disk(config('filesystems.default'))->assertEmpty();
});

test('replacing and removing a profile photo cleans up obsolete files', function () {
    Storage::fake(config('filesystems.default'));
    ['user' => $user] = profileSettingsContext();
    $oldPath = "profile-photos/{$user->id}/old-photo.jpg";
    Storage::put($oldPath, 'old photo');
    $user->update(['profile_photo_path' => $oldPath]);

    $this->actingAs($user)
        ->put(route('profile-settings.update'), [
            'name' => $user->name,
            'phone' => $user->phone,
            'profile_photo' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertSessionHasNoErrors();

    $newPath = $user->refresh()->profile_photo_path;
    expect($newPath)->not->toBeNull()->not->toBe($oldPath);
    Storage::disk(config('filesystems.default'))->assertMissing($oldPath);
    Storage::disk(config('filesystems.default'))->assertExists($newPath);

    $this->actingAs($user)
        ->from(route('profile-settings.edit'))
        ->delete(route('profile-photo.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile-settings.edit'));

    expect($user->refresh()->profile_photo_path)->toBeNull();
    Storage::disk(config('filesystems.default'))->assertMissing($newPath);
});

test('users cannot read or modify another users profile photo', function () {
    Storage::fake(config('filesystems.default'));
    ['user' => $user] = profileSettingsContext();
    $otherUser = User::factory()->create();
    $otherPath = "profile-photos/{$otherUser->id}/private.jpg";
    Storage::put($otherPath, 'private photo');
    $otherUser->update(['profile_photo_path' => $otherPath]);

    $this->actingAs($user)
        ->get(route('profile-photo.show', ['user' => $otherUser->id]))
        ->assertNotFound();

    $this->actingAs($user)
        ->put(route('profile-settings.update'), [
            'user_id' => $otherUser->id,
            'name' => 'Current User',
            'phone' => null,
            'profile_photo_path' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($otherUser->refresh()->profile_photo_path)->toBe($otherPath);
    Storage::disk(config('filesystems.default'))->assertExists($otherPath);
});
