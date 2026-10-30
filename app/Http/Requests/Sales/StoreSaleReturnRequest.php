<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_exchange' => ['sometimes', 'boolean'],
            'replacements' => ['nullable', 'array', 'max:100'],
            'replacements.*.sale_line_id' => ['required', 'integer'],
            'replacements.*.device_identifier' => ['nullable', 'string', 'max:32'],
            'operation_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'reason' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_line_id' => ['required', 'integer', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.restock' => ['required', 'boolean'],
            'refund_amount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'refund_method' => ['nullable', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'refund_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
