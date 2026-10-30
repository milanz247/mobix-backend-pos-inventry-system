<?php

namespace App\Http\Requests\Sales;

use App\SaleSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credit_sale_id' => ['nullable', 'integer'],
            'is_credit' => ['sometimes', 'boolean'],
            'customer_id' => ['nullable', 'integer'],
            'operation_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'source' => ['required', Rule::enum(SaleSource::class)],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:254'],
            'order_discount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'lines.*.device_identifier' => ['nullable', 'string', 'max:32'],
            'payments' => ['nullable', 'array', 'max:5'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payments.*.method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'payments.*.cash_tendered' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
