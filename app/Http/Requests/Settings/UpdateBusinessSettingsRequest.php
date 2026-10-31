<?php

namespace App\Http\Requests\Settings;

use App\BusinessType;
use App\Models\Business;
use App\PermissionName;
use App\TimeFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::BusinessSettingsUpdate->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tax_rate' => ['sometimes', 'required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'name' => ['required', 'string', 'max:150'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', Rule::in(['Sri Lanka'])],
            'currency' => ['required', 'string', Rule::in(['LKR'])],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'time_format' => ['required', Rule::enum(TimeFormat::class)],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function business(): Business
    {
        return Business::query()->where('setup_key', 'initial')->sole();
    }
}
