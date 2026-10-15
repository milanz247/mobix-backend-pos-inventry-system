<?php

namespace App\Http\Requests\Catalog;

use App\CatalogStatus;
use App\Http\Requests\Catalog\Concerns\ScopesCatalogValidation;
use App\Models\Product;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductStatusRequest extends FormRequest
{
    use ScopesCatalogValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');
        $business = $this->currentBusiness();

        return $this->user()?->can(PermissionName::ProductsDeactivate->value)
            && $product instanceof Product
            && $product->business_id === $business->id;
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
            $product = $this->route('product');

            if (! $product instanceof Product) {
                return;
            }

            if ($this->input('status') === CatalogStatus::Inactive->value
                && $product->variants()->whereHas(
                    'includedInBundles.bundleVariant.product',
                    fn (Builder $query) => $query->where('status', CatalogStatus::Active->value),
                )->exists()) {
                $validator->errors()->add('status', 'This product supplies an active combo. Deactivate that combo first.');
            }

            if ($this->input('status') !== CatalogStatus::Active->value) {
                return;
            }

            if (! $product->variants()->where('status', CatalogStatus::Active->value)->exists()) {
                $validator->errors()->add('status', 'A product needs at least one active variant before it can be activated.');
            }

            if ($product->category()->where('status', CatalogStatus::Active->value)->doesntExist()) {
                $validator->errors()->add('status', 'Activate this product\'s category before activating the product.');
            }

            if ($product->brand_id !== null
                && $product->brand()->where('status', CatalogStatus::Active->value)->doesntExist()) {
                $validator->errors()->add('status', 'Activate this product\'s brand before activating the product.');
            }

            if ($product->product_type->value === 'combo') {
                $hasValidBundle = $product->variants()
                    ->where('status', CatalogStatus::Active->value)
                    ->whereHas('bundleComponents')
                    ->whereDoesntHave(
                        'bundleComponents.componentVariant',
                        fn (Builder $query) => $query->where('status', CatalogStatus::Inactive->value)
                            ->orWhereHas('product', fn (Builder $productQuery) => $productQuery->where('status', CatalogStatus::Inactive->value)),
                    )
                    ->exists();

                if (! $hasValidBundle) {
                    $validator->errors()->add('status', 'This combo needs active components before it can be activated.');
                }
            }
        }];
    }
}
