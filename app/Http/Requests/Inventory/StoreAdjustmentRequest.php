<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_with:items', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'decimal:0,3'],
            'product_variant_id' => ['required_without:items', 'integer', 'exists:product_variants,id'],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'quantity' => ['required_without:items', 'numeric', 'gt:0', 'decimal:0,3'],
            'reason' => ['required', Rule::in(['physical_count', 'damaged', 'missing', 'data_correction', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
