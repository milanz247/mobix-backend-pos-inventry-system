<?php

namespace App\Http\Requests\Inventory\Concerns;

use App\ProductCondition;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesInventoryEntry
{
    /** @return array<string, mixed> */
    protected function entryRules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'mode' => ['required', Rule::in(['quantity', 'serialized'])],
            'quantity' => ['exclude_unless:mode,quantity', 'required', 'numeric', 'gt:0', 'decimal:0,3'],
            'unit_cost' => ['exclude_unless:mode,quantity', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'devices' => ['exclude_unless:mode,serialized', 'required', 'array', 'min:1', 'max:100'],
            'devices.*.imei' => ['required', 'string', 'regex:/^[0-9]{8,32}$/'],
            'devices.*.imei2' => ['nullable', 'string', 'regex:/^[0-9]{8,32}$/'],
            'devices.*.serial_number' => ['nullable', 'string', 'max:100'],
            'devices.*.condition' => ['required', Rule::enum(ProductCondition::class)],
            'devices.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'devices.*.unit_selling_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function checkBatchIdentifiers(Validator $validator): void
    {
        $seen = [];

        foreach ($this->input('devices', []) as $index => $device) {
            foreach (['imei', 'imei2'] as $slot) {
                $identifier = preg_replace('/\s+/', '', (string) ($device[$slot] ?? ''));

                if ($identifier === '') {
                    continue;
                }

                if (isset($seen[$identifier])) {
                    $validator->errors()->add("devices.{$index}.{$slot}", __('IMEI values must be unique within the batch.'));
                }

                $seen[$identifier] = true;
            }
        }
    }
}
