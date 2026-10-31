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

    protected function prepareForValidation(): void
    {
        if ($this->user()?->can('sales.costs.view')) {
            return;
        }

        $product = $this->route('product');
        $variants = $this->input('variants', []);
        if (! $product instanceof Product || ! is_array($variants)) {
            return;
        }

        $savedCosts = $product->variants()->pluck('cost_price', 'id');
        foreach ($variants as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $id = filter_var($variant['id'] ?? null, FILTER_VALIDATE_INT);
            $variants[$index]['cost_price'] = $id !== false && $savedCosts->has($id)
                ? (string) $savedCosts->get($id)
                : '0.00';
        }

        $this->merge(['variants' => $variants]);
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
