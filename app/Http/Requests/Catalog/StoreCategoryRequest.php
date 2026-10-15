<?php

namespace App\Http\Requests\Catalog;

use App\CatalogStatus;
use App\Http\Requests\Catalog\Concerns\ScopesCatalogValidation;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    use ScopesCatalogValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::CategoriesCreate->value) ?? false;
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
                Rule::unique('categories', 'name')->where(fn (Builder $query) => $query->where('business_id', $this->currentBusiness()->id)),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
