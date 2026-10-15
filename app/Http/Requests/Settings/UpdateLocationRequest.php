<?php

namespace App\Http\Requests\Settings;

use App\LocationStatus;
use App\LocationType;
use App\Models\Location;
use App\Services\LocationAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location instanceof Location && $this->user()->can('update', $location);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:32', Rule::unique('locations')->where('business_id', app(LocationAccessService::class)->business()->id)->ignore($location instanceof Location ? $location->id : null)],
            'type' => ['required', Rule::enum(LocationType::class)],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::enum(LocationStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => str($this->input('code'))->trim()->upper()->toString()]);
    }
}
