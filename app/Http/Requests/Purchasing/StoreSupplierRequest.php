<?php

namespace App\Http\Requests\Purchasing;

use App\Services\LocationAccessService;
use App\SupplierStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = app(LocationAccessService::class)->business()->id;

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->where('business_id', $businessId)],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:4000'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::enum(SupplierStatus::class)],
        ];
    }
}
