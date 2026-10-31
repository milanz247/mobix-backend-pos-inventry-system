<?php

namespace App\Http\Requests\Purchasing\Concerns;

use App\Models\SerializedDeviceIdentifier;
use App\ProductType;
use App\Services\LocationAccessService;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPurchasePayload
{
    /** @return array<string, mixed> */
    protected function purchaseRules(?int $purchaseId = null): array
    {
        $business = app(LocationAccessService::class)->business();

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $business->id)->where('status', 'active')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('business_id', $business->id)->where('status', 'active')],
            'invoice_number' => [
                'required', 'string', 'max:100',
                Rule::unique('purchases', 'invoice_number')->where('business_id', $business->id)->ignore($purchaseId),
            ],
            'invoice_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $business->id)],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999999.999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'lines.*.devices' => ['sometimes', 'array', 'max:100'],
            'lines.*.devices.*.imei' => ['required', 'string', 'regex:/^[0-9]{8,32}$/'],
            'lines.*.devices.*.imei2' => ['nullable', 'string', 'regex:/^[0-9]{8,32}$/'],
            'lines.*.devices.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.devices.*.condition' => ['required', Rule::in(['new', 'used', 'refurbished'])],
            'lines.*.devices.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'lines.*.devices.*.unit_selling_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
        ];
    }

    /** @return list<callable> */
    protected function purchaseAfterValidation(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('lines') || ! is_array($this->input('lines'))) {
                return;
            }

            $lines = $this->array('lines');
            $business = app(LocationAccessService::class)->business();
            $variantIds = collect($lines)->pluck('product_variant_id')->filter()->map(fn ($id): int => (int) $id)->unique();
            $variants = $business->productVariants()->with('product')->whereIn('id', $variantIds)->get()->keyBy('id');
            $seenVariants = [];
            $seenIdentifiers = [];

            foreach ($lines as $index => $line) {
                $variantId = (int) ($line['product_variant_id'] ?? 0);
                $variant = $variants->get($variantId);
                if ($variant === null || $variant->status->value !== 'active' || $variant->product->status->value !== 'active' || $variant->product->product_type === ProductType::Combo) {
                    $validator->errors()->add("lines.{$index}.product_variant_id", __('Choose an active, non-combo product variant.'));

                    continue;
                }

                if (isset($seenVariants[$variantId])) {
                    $validator->errors()->add("lines.{$index}.product_variant_id", __('A product variant can appear only once per purchase.'));
                }
                $seenVariants[$variantId] = true;
                $devices = $line['devices'] ?? [];

                if ($variant->tracking_mode === VariantTrackingMode::QuantityBased) {
                    if (! is_numeric($line['quantity'] ?? null) || BigDecimal::of((string) ($line['quantity'] ?? '0'))->isLessThanOrEqualTo(BigDecimal::zero())) {
                        $validator->errors()->add("lines.{$index}.quantity", __('Enter a positive quantity for this product.'));
                    }
                    if (! is_numeric($line['unit_cost'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.unit_cost", __('Enter the actual unit cost.'));
                    }
                    if (filled($devices)) {
                        $validator->errors()->add("lines.{$index}.devices", __('Quantity-based products cannot include device IMEIs.'));
                    }

                    continue;
                }

                if (! is_array($devices) || count($devices) < 1) {
                    $validator->errors()->add("lines.{$index}.devices", __('Add at least one serialized device for this variant.'));

                    continue;
                }

                foreach ($devices as $deviceIndex => $device) {
                    foreach (['imei', 'imei2'] as $slot) {
                        $identifier = preg_replace('/\s+/', '', (string) ($device[$slot] ?? ''));
                        if ($identifier === '') {
                            continue;
                        }
                        if (isset($seenIdentifiers[$identifier])) {
                            $validator->errors()->add("lines.{$index}.devices.{$deviceIndex}.{$slot}", __('IMEI values must be unique within this purchase.'));
                        }
                        $seenIdentifiers[$identifier] = true;
                    }
                }
            }

            if ($seenIdentifiers !== []) {
                $exists = SerializedDeviceIdentifier::query()
                    ->where('business_id', $business->id)
                    ->whereIn('identifier', array_keys($seenIdentifiers))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('lines', __('One or more IMEI values have already been received into inventory.'));
                }
            }
        }];
    }
}
