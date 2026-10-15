<?php

namespace App\Http\Requests\Management;

use App\PermissionName;
use App\SystemRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::RolesCreate->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:125', Rule::unique('roles', 'name')->where('guard_name', 'web'), Rule::notIn(array_column(SystemRole::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
        ];
    }
}
