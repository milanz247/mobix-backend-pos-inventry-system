<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ValidatesProductPayload;
use App\Http\Requests\Concerns\AuthorizesStatusChanges;
use App\Models\Product;
use App\PermissionName;
use App\ProductType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use AuthorizesStatusChanges;
    use ValidatesProductPayload;

    public function authorize(): bool
    {
        $product = $this->route('product');
        $type = ProductType::tryFrom((string) $this->input('product_type'));

        return ($this->user()?->can(PermissionName::ProductsUpdate->value) ?? false)
            && $product instanceof Product
            && $this->maySetStatus($product, 'products.deactivate')
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
