<?php

namespace App\Http\Requests\Shifts;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.close') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['actual_cash' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'location_id' => [$this->routeIs('pos.shifts.*') ? 'required' : 'sometimes', 'integer'],
            'variance_reason' => ['nullable', 'string', 'max:2000'],
            'closing_note' => ['nullable', 'string', 'max:2000'], ];
    }
}
