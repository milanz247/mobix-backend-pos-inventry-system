<?php

namespace App\Http\Requests\Shifts;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.open') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'opening_note' => ['nullable', 'string', 'max:2000'], ];
    }
}
