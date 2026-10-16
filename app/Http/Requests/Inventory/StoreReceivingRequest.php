<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Inventory\Concerns\ValidatesInventoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReceivingRequest extends FormRequest
{
    use ValidatesInventoryEntry;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->entryRules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkBatchIdentifiers($validator)];
    }
}
