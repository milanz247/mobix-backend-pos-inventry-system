<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class DamagedStockListService
{
    public function __construct(private readonly LocationAccessService $access) {}

    /** @param array<string, mixed> $filters */
    public function query(User $user, Business $business, array $filters): Builder
    {
        $locationIds = $this->access->accessibleQuery($user)->select('locations.id');
        $quantity = DB::table('damaged_stock_entries as d')->join('product_variants as damaged_variants', 'damaged_variants.id', '=', 'd.product_variant_id')->where('d.business_id', $business->id)->whereIn('d.location_id', $locationIds)
            ->where('d.remaining_quantity', '>', 0)
            ->select('d.product_variant_id', 'd.location_id')
            ->selectRaw('SUM(d.remaining_quantity) AS damaged_quantity, SUM(d.remaining_quantity * d.unit_cost) AS damaged_value, SUM(d.remaining_quantity * damaged_variants.selling_price) AS retail_value, 0 AS device_count, MAX(d.created_at) AS last_damage_at')
            ->groupBy('d.product_variant_id', 'd.location_id');
        $devices = DB::table('serialized_devices as sd')->where('sd.business_id', $business->id)->whereIn('sd.location_id', $locationIds)
            ->where('sd.status', 'damaged')->select('sd.product_variant_id', 'sd.location_id')
            ->join('product_variants as damaged_variants', 'damaged_variants.id', '=', 'sd.product_variant_id')
            ->selectRaw('0 AS damaged_quantity, SUM(sd.unit_cost) AS damaged_value, SUM(COALESCE(sd.unit_selling_price, damaged_variants.selling_price)) AS retail_value, COUNT(*) AS device_count, MAX(sd.updated_at) AS last_damage_at')
            ->groupBy('sd.product_variant_id', 'sd.location_id');
        $query = DB::query()->fromSub($quantity->unionAll($devices), 'damage')
            ->join('product_variants as v', 'v.id', '=', 'damage.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->join('locations as l', 'l.id', '=', 'damage.location_id')
            ->where('v.business_id', $business->id)->where('p.business_id', $business->id);
        foreach (['location_id' => 'l.id', 'category_id' => 'p.category_id', 'brand_id' => 'p.brand_id', 'model_number' => 'v.model_number'] as $field => $column) {
            if (filled($filters[$field] ?? null)) {
                $query->where($column, $filters[$field]);
            }
        }
        if (filled($filters['tracking_mode'] ?? null)) {
            $query->where('v.tracking_mode', $filters['tracking_mode']);
        }
        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(fn (Builder $q) => $q->where('p.name', 'like', "%{$search}%")
                ->orWhere('v.name', 'like', "%{$search}%")->orWhere('v.sku', 'like', "%{$search}%")
                ->orWhere('v.barcode', 'like', "%{$search}%")->orWhere('v.model_number', 'like', "%{$search}%")
                ->orWhereExists(fn (Builder $devices) => $devices->selectRaw('1')->from('serialized_devices as search_devices')
                    ->whereColumn('search_devices.product_variant_id', 'damage.product_variant_id')
                    ->whereColumn('search_devices.location_id', 'damage.location_id')
                    ->where('search_devices.business_id', $business->id)->where('search_devices.status', 'damaged')
                    ->where(fn (Builder $imei) => $imei->where('search_devices.imei', 'like', "%{$search}%")
                        ->orWhere('search_devices.imei2', 'like', "%{$search}%"))));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function summary(Builder $query, bool $showCosts): array
    {
        $row = (clone $query)->selectRaw('COUNT(DISTINCT v.id) AS skus, COUNT(DISTINCT l.id) AS locations, COALESCE(SUM(damage.damaged_quantity), 0) AS quantity, COALESCE(SUM(damage.device_count), 0) AS devices, COALESCE(SUM(damage.damaged_value), 0) AS value, COALESCE(SUM(damage.retail_value), 0) AS retail_value')->first();
        $costValue = BigDecimal::of((string) $row->value)->toScale(2, RoundingMode::HalfUp);
        $retailValue = BigDecimal::of((string) $row->retail_value)->toScale(2, RoundingMode::HalfUp);

        return [
            'skus' => (int) $row->skus, 'locations' => (int) $row->locations,
            'quantity' => BigDecimal::of((string) $row->quantity)->toScale(3)->__toString(), 'devices' => (int) $row->devices,
            'value' => $showCosts ? $costValue->__toString() : null,
            'retail_value' => $retailValue->__toString(),
            'margin' => $showCosts ? $retailValue->minus($costValue)->toScale(2, RoundingMode::HalfUp)->__toString() : null,
        ];
    }

    public function rows(Builder $query): Builder
    {
        return (clone $query)->select([
            'v.id as variant_id', 'v.name as variant', 'v.sku', 'v.barcode', 'v.model_number', 'v.unit', 'v.tracking_mode',
            'p.name as product', 'c.name as category', 'b.name as brand', 'l.id as location_id', 'l.name as location',
        ])->selectRaw('SUM(damage.damaged_quantity) AS quantity, SUM(damage.device_count) AS devices, SUM(damage.damaged_value) AS value, SUM(damage.retail_value) AS retail_value, MAX(damage.last_damage_at) AS last_damage_at')
            ->groupBy('v.id', 'v.name', 'v.sku', 'v.barcode', 'v.model_number', 'v.unit', 'v.tracking_mode', 'p.name', 'c.name', 'b.name', 'l.id', 'l.name')
            ->orderBy('c.name')->orderBy('b.name')->orderBy('v.sku')->orderBy('l.name');
    }

    /** @return list<array<string, mixed>> */
    public function breakdown(Builder $query, string $group, bool $showCosts): array
    {
        $column = match ($group) {
            'brand' => 'b.name', 'branch' => 'l.name', default => 'c.name'
        };

        $groups = (clone $query)->selectRaw("COALESCE({$column}, 'Unbranded') AS name, COUNT(DISTINCT v.id) AS skus, SUM(damage.damaged_quantity) AS quantity, SUM(damage.device_count) AS devices, SUM(damage.damaged_value) AS value, SUM(damage.retail_value) AS retail_value")
            ->groupBy($column)->orderBy($column)->get()->map(fn (object $row): array => [
                'name' => $row->name, 'skus' => (int) $row->skus,
                'quantity' => BigDecimal::of((string) $row->quantity)->toScale(3)->__toString(), 'devices' => (int) $row->devices,
                'value' => $showCosts ? BigDecimal::of((string) $row->value)->toScale(2, RoundingMode::HalfUp)->__toString() : null,
                'retail_value' => BigDecimal::of((string) $row->retail_value)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'margin' => $showCosts ? BigDecimal::of((string) $row->retail_value)->minus((string) $row->value)->toScale(2, RoundingMode::HalfUp)->__toString() : null,
            ])->all();

        return array_values($groups);
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row, bool $showCosts): array
    {
        return [
            'id' => $row->variant_id.'-'.$row->location_id, 'product' => $row->product, 'variant' => $row->variant,
            'sku' => $row->sku, 'barcode' => $row->barcode, 'model_number' => $row->model_number,
            'category' => $row->category, 'brand' => $row->brand, 'location' => $row->location, 'unit' => $row->unit,
            'tracking_mode' => $row->tracking_mode, 'quantity' => BigDecimal::of((string) $row->quantity)->toScale(3)->__toString(),
            'devices' => (int) $row->devices, 'last_damage_at' => $row->last_damage_at,
            'value' => $showCosts ? BigDecimal::of((string) $row->value)->toScale(2, RoundingMode::HalfUp)->__toString() : null,
            'retail_value' => BigDecimal::of((string) $row->retail_value)->toScale(2, RoundingMode::HalfUp)->__toString(),
            'margin' => $showCosts ? BigDecimal::of((string) $row->retail_value)->minus((string) $row->value)->toScale(2, RoundingMode::HalfUp)->__toString() : null,
        ];
    }
}
