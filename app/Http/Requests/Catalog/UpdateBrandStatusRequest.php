<?php

namespace App\Http\Requests\Catalog;

use App\CatalogStatus;
use App\Http\Requests\Catalog\Concerns\ScopesCatalogValidation;
use App\Models\Brand;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBrandStatusRequest extends FormRequest
{
    use ScopesCatalogValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $brand = $this->route('brand');
        $business = $this->currentBusiness();

        return $this->user()?->can(PermissionName::BrandsDeactivate->value)
            && $brand instanceof Brand
            && $brand->business_id === $business->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $brand = $this->route('brand');

            if ($brand instanceof Brand
                && $this->input('status') === CatalogStatus::Inactive->value
                && $brand->products()->where('status', CatalogStatus::Active->value)->exists()) {
                $validator->errors()->add('status', 'Deactivate the active products using this brand first.');
            }
        }];
    }
}
