<?php

namespace App\Http\Requests\Purchasing;

use App\Http\Requests\Purchasing\Concerns\ValidatesPurchasePayload;
use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
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
        $purchase = $this->route('purchase');

        return $this->purchaseRules($purchase instanceof Purchase ? $purchase->id : null);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return $this->purchaseAfterValidation();
    }
}
