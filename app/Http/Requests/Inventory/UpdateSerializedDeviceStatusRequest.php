<?php

namespace App\Http\Requests\Inventory;

use App\SerializedDeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSerializedDeviceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([SerializedDeviceStatus::InStock->value, SerializedDeviceStatus::InRepair->value, SerializedDeviceStatus::Damaged->value])],
            'reason' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
