<?php

namespace App\Http\Requests\Purchasing;

use App\Http\Requests\Purchasing\Concerns\ValidatesPurchasePayload;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequest extends FormRequest
{
    use ValidatesPurchasePayload;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->purchaseRules((int) $this->route('purchase')->id);
    }

    public function after(): array
    {
        return $this->purchaseAfterValidation();
    }
}
