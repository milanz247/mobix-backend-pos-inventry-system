<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleLineComponent;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Storage;

class SalePageData
{
    /** @return array<string, mixed> */
    public function sale(Sale $sale, bool $showCosts = false): array
    {
        $sale->loadMissing([
            'business', 'location', 'cashier', 'lines.variant.product', 'lines.serializedDevice',
            'lines.components.returnLines', 'lines.returnLines', 'payments.receiver', 'returns.processor', 'returns.lines.saleLine', 'returns.refunds.processor', 'refunds',
        ]);

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'posted_at' => $sale->posted_at->format('Y-m-d H:i:s'),
            'status' => $sale->status->value,
            'source' => $sale->source->value,
            'source_label' => $sale->source->label(),
            'payment_status' => $sale->payment_status->value,
            'location' => ['id' => $sale->location->id, 'name' => $sale->location->name, 'code' => $sale->location->code],
            'cashier' => $sale->cashier->name,
            'customer' => [
                'name' => $sale->customer_name,
                'phone' => $sale->customer_phone,
                'email' => $sale->customer_email,
            ],
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'tax_total' => $sale->tax_total,
            'total' => $sale->total,
            'paid_total' => $sale->paid_total,
            'returned_total' => $sale->returns->reduce(fn (BigDecimal $sum, SaleReturn $return): BigDecimal => $sum->plus((string) $return->total), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp)->__toString(),
            'refund_total' => $sale->refunds->reduce(fn (BigDecimal $sum, SaleRefund $refund): BigDecimal => $sum->plus((string) $refund->amount), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp)->__toString(),
            'notes' => $sale->notes,
            'lines' => $sale->lines->map(function (SaleLine $line) use ($showCosts): array {
                $returnedLines = $line->returnLines->where('is_component', false);
                $returnedQuantity = $returnedLines->reduce(fn (BigDecimal $sum, $item): BigDecimal => $sum->plus((string) $item->quantity), BigDecimal::zero());
                $returnedValue = $returnedLines->reduce(fn (BigDecimal $sum, $item): BigDecimal => $sum->plus((string) $item->total), BigDecimal::zero());
                $remainingQuantity = BigDecimal::max(BigDecimal::of((string) $line->quantity)->minus($returnedQuantity), BigDecimal::zero());

                return [
                    'id' => $line->id,
                    'product_name' => $line->product_name_snapshot,
                    'variant_name' => $line->variant_name_snapshot,
                    'sku' => $line->sku_snapshot,
                    'barcode' => $line->barcode_snapshot,
                    'tracking_mode' => $line->tracking_mode->value,
                    'is_combo' => $line->is_combo,
                    'imei' => $line->imei_snapshot,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $line->discount_amount,
                    'subtotal' => $line->subtotal,
                    'total' => $line->total,
                    'cost_snapshot' => $showCosts ? $line->cost_snapshot : null,
                    'returned_quantity' => $returnedQuantity->toScale(3, RoundingMode::HalfUp)->__toString(),
                    'returnable_quantity' => $remainingQuantity->toScale(3, RoundingMode::HalfUp)->__toString(),
                    'returned_value' => $returnedValue->toScale(2, RoundingMode::HalfUp)->__toString(),
                    'has_serialized_components' => $line->components->contains(fn ($component): bool => $component->serialized_device_id !== null),
                    'components' => $line->components->map(fn (SaleLineComponent $component): array => [
                        'name' => $component->name_snapshot,
                        'sku' => $component->sku_snapshot,
                        'quantity' => $component->quantity,
                        'imei' => $component->imei_snapshot,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'payments' => $sale->payments->map(fn (SalePayment $payment): array => [
                'amount' => $payment->amount,
                'cash_tendered' => $payment->cash_tendered,
                'change_given' => $payment->change_given,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at->format('Y-m-d H:i:s'),
                'received_by' => $payment->receiver->name,
            ])->values()->all(),
            'returns' => $sale->returns->map(fn (SaleReturn $return): array => [
                'id' => $return->id,
                'return_number' => $return->return_number,
                'total' => $return->total,
                'refund_total' => $return->refund_total,
                'reason' => $return->reason,
                'processed_at' => $return->processed_at->format('Y-m-d H:i:s'),
                'processed_by' => $return->processor->name,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function business(Business $business): array
    {
        return [
            'name' => $business->name,
            'phone' => $business->phone,
            'email' => $business->email,
            'address' => $business->address,
            'city' => $business->city,
            'logo_url' => $business->logo_path === null ? null : Storage::disk('public')->url($business->logo_path),
            'currency' => $business->currency,
            'invoice_footer' => $business->invoice_footer,
            'invoice_return_policy' => $business->invoice_return_policy,
            'invoice_title' => $business->invoice_title,
            'invoice_paper_size' => $business->invoice_paper_size,
            'invoice_thermal_width' => $business->invoice_thermal_width,
            'invoice_accent_color' => $business->invoice_accent_color,
            'invoice_show_logo' => $business->invoice_show_logo,
            'invoice_show_business_details' => $business->invoice_show_business_details,
            'invoice_show_customer' => $business->invoice_show_customer,
            'invoice_show_cashier' => $business->invoice_show_cashier,
            'invoice_show_payment_details' => $business->invoice_show_payment_details,
            'invoice_show_tax' => $business->invoice_show_tax,
            'invoice_show_product_codes' => $business->invoice_show_product_codes,
            'invoice_show_line_discounts' => $business->invoice_show_line_discounts,
        ];
    }
}
