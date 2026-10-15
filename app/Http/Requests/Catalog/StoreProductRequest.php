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
