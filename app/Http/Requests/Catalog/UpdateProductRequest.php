<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ValidatesProductPayload;
use App\Models\Product;
use App\PermissionName;
use App\ProductType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesProductPayload;

    public function authorize(): bool
    {
        $product = $this->route('product');
        $type = ProductType::tryFrom((string) $this->input('product_type'));

        return ($this->user()?->can(PermissionName::ProductsUpdate->value) ?? false)
            && $product instanceof Product
            && $product->business_id === $this->currentBusiness()->id
            && $this->mayManageProduct($type);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->productRules();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return $this->productAfter();
    }
}
