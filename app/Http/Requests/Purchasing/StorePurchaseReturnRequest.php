<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
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
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'return_number' => ['required', 'string', 'max:100'],
            'operation_key' => ['required', 'uuid'],
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.purchase_line_id' => ['required', 'integer', 'exists:purchase_lines,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999999.999'],
            'lines.*.serialized_device_ids' => ['sometimes', 'array', 'max:100'],
            'lines.*.serialized_device_ids.*' => ['integer', 'distinct', 'exists:serialized_devices,id'],
        ];
    }
}
