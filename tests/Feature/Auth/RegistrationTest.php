<?php

use App\Actions\Fortify\CreateNewUser;
use App\BusinessStatus;
use App\BusinessType;
use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

function validRegistrationData(array $overrides = []): array
{
    return array_replace([
        'business_name' => 'Nalaka Mobile',
        'business_type' => BusinessType::MobileSalesAndRepairs->value,
        'business_email' => 'shop@example.com',
        'business_phone' => '0771234567',
        'business_address' => '123 Main Street',
        'business_city' => 'Colombo',
        'currency' => 'LKR',
        'name' => 'Test Owner',
        'email' => 'owner@example.com',
        'phone' => '0711234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides);
}

test('initial business registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('initial registration creates the business and owner atomically', function () {
    $unrelatedUser = User::factory()->create();

    $response = $this->post(route('register.store'), validRegistrationData([
        'owner_id' => $unrelatedUser->id,
        'role' => 'administrator',
        'setup_key' => 'another-business',
    ]));

    $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
    $business = Business::query()->sole();
    $location = Location::query()->sole();

    $this->assertAuthenticatedAs($owner);
    $response->assertRedirect(route('dashboard', absolute: false));
    expect($owner->name)->toBe('Test Owner');
    expect(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();
    expect($owner->phone)->toBe('0711234567');
    expect(Hash::check('password', $owner->password))->toBeTrue();
    expect($business->owner_id)->toBe($owner->id);
    expect($business->setup_key)->toBe('initial');
    expect($business->name)->toBe('Nalaka Mobile');
    expect($business->business_type)->toBe(BusinessType::MobileSalesAndRepairs);
    expect($business->email)->toBe('shop@example.com');
    expect($business->phone)->toBe('0771234567');
    expect($business->address)->toBe('123 Main Street');
    expect($business->city)->toBe('Colombo');
    expect($business->country)->toBe('Sri Lanka');
    expect($business->currency)->toBe('LKR');
    expect($business->logo_path)->toBeNull();
    expect($business->status)->toBe(BusinessStatus::Active);
    expect($business->owner->is($owner))->toBeTrue();
    expect($owner->hasRole(SystemRole::Owner->value))->toBeTrue();
    expect($location->business_id)->toBe($business->id);
    expect($location->name)->toBe('Nalaka Mobile');
    expect($location->code)->toBe('MAIN');
    expect($location->type)->toBe(LocationType::Shop);
    expect($location->phone)->toBe('0771234567');
    expect($location->email)->toBe('shop@example.com');
    expect($location->address)->toBe('123 Main Street');
    expect($location->city)->toBe('Colombo');
    expect($location->is_main)->toBeTrue();
    expect($location->status)->toBe(LocationStatus::Active);
    expect($location->users()->whereKey($owner->id)->exists())->toBeTrue();
    $this->assertDatabaseCount('businesses', 1);
    $this->assertDatabaseCount('locations', 1);
});

test('email verification is disabled for the initial owner account', function () {
    expect(Features::enabled(Features::emailVerification()))->toBeFalse();
    expect(Route::has('verification.notice'))->toBeFalse();
});

test('required business and owner fields are validated', function () {
    $response = $this->post(route('register.store'), []);

    $response->assertSessionHasErrors([
        'business_name',
        'business_type',
        'business_phone',
        'business_address',
        'business_city',
        'currency',
        'name',
        'email',
        'password',
        'password_confirmation',
    ]);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('businesses', 0);
});

test('invalid email and mismatched passwords are rejected', function () {
    $response = $this->post(route('register.store'), validRegistrationData([
        'email' => 'not-an-email',
        'password_confirmation' => 'different-password',
    ]));

    $response->assertSessionHasErrors(['email', 'password']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('businesses', 0);
});

test('owner login email must be unique', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $response = $this->post(route('register.store'), validRegistrationData());

    $response->assertSessionHasErrors('email');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('businesses', 0);
});

test('business and owner creation roll back together when business creation fails', function () {
    Business::creating(function (): never {
        throw new RuntimeException('Business creation failed.');
    });

    try {
        expect(fn () => app(CreateNewUser::class)->create(validRegistrationData()))
            ->toThrow(RuntimeException::class, 'Business creation failed.');
    } finally {
        Business::flushEventListeners();
    }

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('locations', 0);
});

test('business owner and location creation roll back together when location creation fails', function () {
    Location::creating(function (): never {
        throw new RuntimeException('Location creation failed.');
    });

    try {
        expect(fn () => app(CreateNewUser::class)->create(validRegistrationData()))
            ->toThrow(RuntimeException::class, 'Location creation failed.');
    } finally {
        Location::flushEventListeners();
    }

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('locations', 0);
});

test('registration is unavailable after initial setup is complete', function () {
    Business::factory()->create();

    $this->get(route('register'))->assertNotFound();

    $response = $this->post(route('register.store'), validRegistrationData([
        'email' => 'second-owner@example.com',
    ]));

    $response->assertSessionHasErrors('registration');
    $this->assertDatabaseCount('businesses', 1);
    $this->assertDatabaseCount('users', 1);
});

test('the database singleton constraint rejects a second business', function () {
    Business::factory()->create();
    $secondOwner = User::factory()->create();

    expect(fn () => Business::factory()->for($secondOwner, 'owner')->create())
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('businesses', 1);
});

test('the database rejects duplicate main locations for one business', function () {
    $business = Business::factory()->create();
    Location::factory()->for($business)->create(['code' => 'MAIN', 'is_main' => true]);

    expect(fn () => Location::factory()->for($business)->create(['code' => 'SECOND', 'is_main' => true]))
        ->toThrow(QueryException::class);

    expect($business->locations()->where('is_main', true)->count())->toBe(1);
});
