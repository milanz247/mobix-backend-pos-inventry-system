<?php

namespace App\Http\Requests\Management;

use App\PermissionName;
use App\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(PermissionName::UsersCreate->value)
            && $this->user()->can(PermissionName::UsersAssignRoles->value)
            && (! $this->exists('location_ids') || $this->user()->can(PermissionName::LocationsAssignUsers->value));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'send_invite' => ['nullable', 'boolean'],
            'password' => ['required_unless:send_invite,1', 'nullable', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::exists('locations', 'id')],
        ];
    }
}
