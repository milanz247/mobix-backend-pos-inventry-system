<?php

namespace App\Http\Requests\Catalog;

use App\CatalogStatus;
use App\Http\Requests\Catalog\Concerns\ScopesCatalogValidation;
use App\Models\Category;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryStatusRequest extends FormRequest
{
    use ScopesCatalogValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $category = $this->route('category');
        $business = $this->currentBusiness();

        return $this->user()?->can(PermissionName::CategoriesDeactivate->value)
            && $category instanceof Category
            && $category->business_id === $business->id;
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
            $category = $this->route('category');

            if ($category instanceof Category
                && $this->input('status') === CatalogStatus::Inactive->value
                && $category->products()->where('status', CatalogStatus::Active->value)->exists()) {
                $validator->errors()->add('status', 'Deactivate the active products in this category first.');
            }
        }];
    }
}
