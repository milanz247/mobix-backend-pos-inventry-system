<?php

namespace App\Actions\Fortify;

use App\BusinessType;
use App\Concerns\PasswordValidationRules;
use App\Concerns\UserValidationRules;
use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, UserValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $this->ensureRegistrationIsAvailable();

        $validated = Validator::make($input, [
            'business_name' => ['required', 'string', 'max:150'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'business_email' => ['nullable', 'string', 'email', 'max:254'],
            'business_phone' => ['required', 'string', 'max:32'],
            'business_address' => ['required', 'string', 'max:1000'],
            'business_city' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', Rule::in(['LKR'])],
            ...$this->userRules(),
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],
        ])->validate();

        try {
            return DB::transaction(function () use ($validated): User {
                $this->ensureRegistrationIsAvailable();

                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'password' => $validated['password'],
                ]);

                $business = $user->ownedBusiness()->create([
                    'name' => $validated['business_name'],
                    'business_type' => $validated['business_type'],
                    'email' => $validated['business_email'] ?? null,
                    'phone' => $validated['business_phone'],
                    'address' => $validated['business_address'],
                    'city' => $validated['business_city'],
                    'currency' => $validated['currency'],
                ]);

                $location = new Location([
                    'name' => $business->name,
                    'code' => 'MAIN',
                    'type' => LocationType::Shop,
                    'phone' => $business->phone,
                    'email' => $business->email,
                    'address' => $business->address,
                    'city' => $business->city,
                    'status' => LocationStatus::Active,
                ]);
                $location->forceFill(['is_main' => true]);
                $business->locations()->save($location);
                $location->users()->attach($user->id);

                $ownerRole = Role::findOrCreate(SystemRole::Owner->value, 'web');
                $user->assignRole($ownerRole);

                return $user;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (Business::query()->exists()) {
                $this->registrationUnavailable();
            }

            throw $exception;
        }
    }

    private function ensureRegistrationIsAvailable(): void
    {
        if (Business::query()->exists()) {
            $this->registrationUnavailable();
        }
    }

    private function registrationUnavailable(): never
    {
        throw ValidationException::withMessages([
            'registration' => __('Initial business registration has already been completed.'),
        ]);
    }
}
