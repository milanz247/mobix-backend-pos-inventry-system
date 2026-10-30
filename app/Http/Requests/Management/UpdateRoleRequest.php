<?php

namespace App\Http\Requests\Management;

use App\Models\Role;
use App\PermissionName;
use App\SystemRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::RolesUpdate->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        $roleId = $role instanceof Role ? $role->id : null;
        $nameRules = ['required', 'string', 'max:125', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($roleId)];

        if (! ($role instanceof Role && $role->is_system)) {
            $nameRules[] = Rule::notIn(array_column(SystemRole::cases(), 'value'));
        }

        return [
            'name' => $nameRules,
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
        ];
    }
}
