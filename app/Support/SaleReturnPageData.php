<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Services\SaleService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class SaleReturnPageData
{
    /** @return array<string, mixed> */
    public function receipt(Sale $sale, SaleReturn $return, SaleService $sales): array
    {
        $return->loadMissing(['lines.saleLine.variant', 'replacements.variant', 'refunds', 'processor']);
        $snapshot = $return->balance_after !== null;

        return [
            'id' => $return->id, 'return_number' => $return->return_number,
            'sale_id' => $sale->id, 'sale_number' => $sale->sale_number,
            'date' => $return->processed_at->format('Y-m-d H:i:s'), 'processed_by' => $return->processor->name,
            'is_exchange' => (bool) $return->is_exchange, 'reason' => $return->reason, 'notes' => $return->notes,
            'location' => $sale->location->name,
            'customer' => ['name' => $sale->customer_name, 'phone' => $sale->customer_phone],
            'total' => $return->total, 'refund_total' => $return->refund_total,
            'balance' => $snapshot ? $return->balance_after : $sales->outstanding($sale)->__toString(),
            'balance_is_snapshot' => $snapshot,
            'net_total' => $snapshot ? $return->net_total_after : BigDecimal::of((string) $sale->total)->minus((string) $sale->returns()->sum('total'))->toScale(2, RoundingMode::HalfUp)->__toString(),
            'net_paid' => $snapshot ? $return->net_paid_after : BigDecimal::of((string) $sale->paid_total)->minus((string) $sale->refunds()->sum('amount'))->toScale(2, RoundingMode::HalfUp)->__toString(),
            'lines' => $return->lines->where('is_component', false)->map(fn (SaleReturnLine $line): array => [
                'id' => $line->id, 'product' => $line->saleLine->product_name_snapshot, 'variant' => $line->saleLine->variant_name_snapshot,
                'sku' => $line->saleLine->sku_snapshot, 'model_number' => $line->saleLine->variant->model_number,
                'imei' => $line->saleLine->imei_snapshot, 'quantity' => $line->quantity, 'total' => $line->total,
                'condition' => $line->restock ? 'Saleable stock' : 'Damaged stock',
            ])->values()->all(),
            'replacements' => $return->replacements->map(fn (SaleLine $line): array => [
                'id' => $line->id, 'product' => $line->product_name_snapshot, 'variant' => $line->variant_name_snapshot,
                'sku' => $line->sku_snapshot, 'model_number' => $line->variant->model_number,
                'imei' => $line->imei_snapshot, 'quantity' => $line->quantity, 'total' => $line->total,
            ])->all(),
            'refunds' => $return->refunds->map(fn ($refund): array => [
                'amount' => $refund->amount, 'method' => $refund->method, 'reference' => $refund->reference,
            ])->all(),
        ];
    }
}
