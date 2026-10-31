<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ValidatesProductPayload;
use App\PermissionName;
use App\ProductType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesProductPayload;

    public function authorize(): bool
    {
        $type = ProductType::tryFrom((string) $this->input('product_type'));

        return ($this->user()?->can(PermissionName::ProductsCreate->value) ?? false)
            && $this->mayManageProduct($type);
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->can('sales.costs.view')) {
            return;
        }

        $variants = $this->input('variants', []);
        if (! is_array($variants)) {
            return;
        }

        foreach ($variants as $index => $variant) {
            if (is_array($variant)) {
                $variants[$index]['cost_price'] = '0.00';
            }
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
