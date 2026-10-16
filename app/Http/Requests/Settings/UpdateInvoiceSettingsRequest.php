<?php

namespace App\Http\Requests\Settings;

use App\Models\Business;
use App\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::BusinessSettingsUpdate->value) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'invoice_title' => ['required', 'string', 'max:100'],
            'invoice_paper_size' => ['required', Rule::in(['a4', 'thermal'])],
            'invoice_accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'invoice_footer' => ['nullable', 'string', 'max:2000'],
            'invoice_return_policy' => ['nullable', 'string', 'max:2000'],
            'invoice_show_logo' => ['required', 'boolean'],
            'invoice_show_business_details' => ['required', 'boolean'],
            'invoice_show_customer' => ['required', 'boolean'],
            'invoice_show_cashier' => ['required', 'boolean'],
            'invoice_show_payment_details' => ['required', 'boolean'],
            'invoice_show_tax' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $checkboxes = [
            'invoice_show_logo',
            'invoice_show_business_details',
            'invoice_show_customer',
            'invoice_show_cashier',
            'invoice_show_payment_details',
            'invoice_show_tax',
        ];

        $unchecked = [];

        foreach ($checkboxes as $checkbox) {
            if (! $this->exists($checkbox)) {
                $unchecked[$checkbox] = false;
            }
        }

        $this->merge($unchecked);
    }

    public function business(): Business
    {
        return Business::query()->sole();
    }
}
