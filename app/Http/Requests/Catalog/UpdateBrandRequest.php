<?php

namespace App\Http\Requests\Catalog;

use App\CatalogStatus;
use App\Http\Requests\Catalog\Concerns\ScopesCatalogValidation;
use App\Http\Requests\Concerns\AuthorizesStatusChanges;
use App\Models\Brand;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBrandRequest extends FormRequest
{
    use AuthorizesStatusChanges;
    use ScopesCatalogValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $brand = $this->route('brand');
        $business = $this->currentBusiness();

        return $this->user()?->can(PermissionName::BrandsUpdate->value)
            && $brand instanceof Brand
            && $this->maySetStatus($brand, 'brands.deactivate')
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
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('brands', 'name')
                    ->where(fn (Builder $query) => $query->where('business_id', $this->currentBusiness()->id))
                    ->ignore($this->route('brand')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
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
