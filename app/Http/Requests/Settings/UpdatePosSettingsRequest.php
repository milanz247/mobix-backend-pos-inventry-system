<?php

namespace App\Http\Requests\Settings;

use App\Models\Business;
use App\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::BusinessSettingsUpdate->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'pos_show_sale_source' => ['required', 'boolean'],
            'pos_show_customer_name' => ['required', 'boolean'],
            'pos_show_customer_phone' => ['required', 'boolean'],
            'pos_show_customer_email' => ['required', 'boolean'],
            'pos_show_sale_notes' => ['required', 'boolean'],
            'pos_show_pay_exact' => ['required', 'boolean'],
            'pos_allow_price_override' => ['required', 'boolean'],
            'pos_require_imei_on_sale' => ['required', 'boolean'],
            'pos_show_date_time' => ['required', 'boolean'],
            'pos_show_sales_history' => ['required', 'boolean'],
            'pos_show_category_filter' => ['required', 'boolean'],
            'pos_show_brand_filter' => ['required', 'boolean'],
            'pos_show_location_filter' => ['required', 'boolean'],
            'pos_show_receipt_after_sale' => ['required', 'boolean'],
            'pos_auto_print_receipt' => ['required', 'boolean'],
            'invoice_paper_size' => ['required', Rule::in(['a4', 'thermal'])],
            'invoice_thermal_width' => ['required', 'integer', Rule::in([58, 80])],
            'pos_products_per_page' => ['required', 'integer', Rule::in([12, 24, 36, 48])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $checkboxes = [
            'pos_show_sale_source',
            'pos_show_customer_name',
            'pos_show_customer_phone',
            'pos_show_customer_email',
            'pos_show_sale_notes',
            'pos_show_pay_exact',
            'pos_allow_price_override',
            'pos_require_imei_on_sale',
            'pos_show_date_time',
            'pos_show_sales_history',
            'pos_show_category_filter',
            'pos_show_brand_filter',
            'pos_show_location_filter',
            'pos_show_receipt_after_sale',
            'pos_auto_print_receipt',
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
        return Business::query()->where('setup_key', 'initial')->sole();
    }
}
