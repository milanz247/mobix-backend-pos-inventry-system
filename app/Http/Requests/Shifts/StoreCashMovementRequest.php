<?php

namespace App\Http\Requests\Shifts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.movements') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'],
            'location_id' => [$this->routeIs('pos.shifts.*') ? 'required' : 'sometimes', 'integer'],
            'type' => ['required', Rule::in(['cash_in', 'cash_out', 'expense', 'drop'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'supporting_reference' => ['nullable', 'string', 'max:150'], ];
    }
}
