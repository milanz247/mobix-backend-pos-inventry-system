<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
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
            'source_location_id' => ['required', 'integer', 'exists:locations,id', 'different:destination_location_id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id'],
            'items' => ['present', 'array', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'device_ids' => ['present', 'array', 'max:100'],
            'device_ids.*' => ['integer', 'distinct', 'exists:serialized_devices,id'],
            'reference' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function ($validator): void {
            if (count($this->input('items', [])) === 0 && count($this->input('device_ids', [])) === 0) {
                $validator->errors()->add('items', __('Add at least one quantity item or serialized device.'));
            }
        }];
    }
}
