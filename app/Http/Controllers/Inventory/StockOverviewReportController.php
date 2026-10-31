<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\LocationAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOverviewReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access): Response
    {
        $costs = $request->user()->can('sales.costs.view');
        $query = $this->query($request, $access);
        $page = (clone $query)->orderBy('location')->orderBy('sku')->paginate(50)->withQueryString()->through(fn ($row) => $this->visibleRow($row, $costs));
        $totals = (clone $query)->selectRaw('SUM(quantity) as quantity, SUM(cost_value) as cost, SUM(retail_value) as retail, COUNT(*) as products')->first();
        $locations = (clone $query)->selectRaw('location, SUM(quantity) as quantity, SUM(cost_value) as cost, SUM(retail_value) as retail, COUNT(*) as products')->groupBy('location')->get();
        $clean = function ($row) use ($costs) {
            $data = (array) $row;
            foreach (['quantity', 'retail', 'products'] as $key) {
                $data[$key] = (float) ($data[$key] ?? 0);
            } $data['cost'] = $costs ? (float) ($data['cost'] ?? 0) : null;

            return $data;
        };
        $business = $access->business();
        $allowed = $access->accessibleQuery($request->user())->where('status', 'active')->get(['id', 'name', 'code']);

        return Inertia::render('inventory/StockOverviewReport', [
            'rows' => $page->items(), 'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl(), 'total' => $page->total()],
            'totals' => $clean($totals), 'locationSummary' => $locations->map($clean), 'canViewCosts' => $costs,
            'locations' => $allowed, 'categories' => $business->categories()->get(['id', 'name']), 'brands' => $business->brands()->get(['id', 'name']),
            'filters' => $this->filters($request, $allowed->modelKeys()), 'currency' => $business->currency, 'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function excel(Request $request, LocationAccessService $access): StreamedResponse
    {
        $costs = $request->user()->can('sales.costs.view');
        $columns = ['location' => 'Location', 'product' => 'Product', 'variant' => 'Variant', 'sku' => 'SKU', 'category' => 'Category', 'brand' => 'Brand', 'tracking' => 'Tracking', 'quantity' => 'Quantity', 'unit_selling_price' => 'Unit price', 'retail_value' => 'Retail value'];
        if ($costs) {
            $columns += ['unit_cost' => 'Unit cost estimate', 'cost_value' => 'Cost value estimate'];
        }
        $query = $this->query($request, $access)->orderBy('location')->orderBy('sku');

        return response()->streamDownload(function () use ($columns, $query) {
            $sheetPath = tempnam(sys_get_temp_dir(), 'stock-sheet-');
            $zipPath = tempnam(sys_get_temp_dir(), 'stock-xlsx-');
            $handle = fopen($sheetPath, 'wb');
            try {
                fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
                $write = function (array $values, bool $header = false) use ($handle) {
                    fwrite($handle, '<row>');
                    foreach ($values as $key => $value) {
                        $numeric = ! $header && in_array($key, ['quantity', 'unit_selling_price', 'retail_value', 'unit_cost', 'cost_value'], true);
                        $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        fwrite($handle, $numeric ? '<c><v>'.$escaped.'</v></c>' : '<c t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>');
                    }
                    fwrite($handle, '</row>');
                };
                $write($columns, true);
                foreach ($query->lazy(500) as $row) {
                    $values = [];
                    foreach ($columns as $key => $label) {
                        $values[$key] = $row->$key;
                    } $write($values);
                }
                fwrite($handle, '</sheetData></worksheet>');
                fclose($handle);
                $handle = null;
                $zip = new \ZipArchive;
                if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Could not create export.');
                }
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
                $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
                $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Stock overview" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
                $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
                $zip->close();
                readfile($zipPath);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                } @unlink($sheetPath);
                @unlink($zipPath);
            }
        }, 'stock-overview.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(Request $request, LocationAccessService $access): View
    {
        return view('reports.stock-overview', ['rows' => $this->query($request, $access)->orderBy('location')->orderBy('sku')->lazy(500), 'costs' => $request->user()->can('sales.costs.view'), 'business' => $access->business()]);
    }

    private function filters(Request $request, array $locationIds): array
    {
        $requestedLocation = $request->integer('location_id') ?: null;

        return [
            'search' => $request->string('search')->trim()->toString(),
            'location_id' => $requestedLocation !== null && in_array($requestedLocation, $locationIds, true) ? $requestedLocation : null,
            'category_id' => $request->integer('category_id') ?: null,
            'brand_id' => $request->integer('brand_id') ?: null,
        ];
    }

    private function query(Request $request, LocationAccessService $access): QueryBuilder
    {
        $business = $access->business();
        $ids = $access->accessibleQuery($request->user())->where('status', 'active')->pluck('id')->all();
        $filters = $this->filters($request, $ids);
        $base = function (string $table) use ($business, $ids, $filters) {
            return DB::table($table)->join('product_variants as v', 'v.id', '=', 's.product_variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')->join('locations as l', 'l.id', '=', 's.location_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->where('s.business_id', $business->id)->whereIn('s.location_id', $ids)
                ->when($filters['location_id'], fn ($q, $id) => $q->where('s.location_id', $id))
                ->when($filters['category_id'], fn ($q, $id) => $q->where('p.category_id', $id))
                ->when($filters['brand_id'], fn ($q, $id) => $q->where('p.brand_id', $id))
                ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q->where('p.name', 'like', '%'.$filters['search'].'%')->orWhere('v.sku', 'like', '%'.$filters['search'].'%')->orWhere('v.barcode', 'like', '%'.$filters['search'].'%')->orWhere('v.name', 'like', '%'.$filters['search'].'%')));
        };
        $columns = "l.name as location, p.name as product, v.name as variant, v.sku, COALESCE(c.name, 'Uncategorised') as category, COALESCE(b.name, '—') as brand";
        $quantity = $base('inventory_stocks as s')->where('v.tracking_mode', 'quantity_based')->whereNotIn('p.product_type', ['service', 'combo'])
            ->selectRaw($columns.", 'Quantity based' as tracking, s.quantity_on_hand as quantity, v.cost_price as unit_cost, v.selling_price as unit_selling_price, s.quantity_on_hand * v.cost_price as cost_value, s.quantity_on_hand * v.selling_price as retail_value");
        $devices = $base('serialized_devices as s')->where('s.status', 'in_stock')
            ->selectRaw($columns.", 'Serialized' as tracking, COUNT(*) as quantity, AVG(s.unit_cost) as unit_cost, AVG(COALESCE(s.unit_selling_price,v.selling_price)) as unit_selling_price, SUM(s.unit_cost) as cost_value, SUM(COALESCE(s.unit_selling_price,v.selling_price)) as retail_value")
            ->groupBy('s.location_id', 'v.id', 'l.name', 'p.name', 'v.name', 'v.sku', 'c.name', 'b.name');

        return DB::query()->fromSub($quantity->unionAll($devices), 'stock');
    }

    private function visibleRow(object $row, bool $costs): array
    {
        $data = (array) $row;
        foreach (['quantity', 'unit_cost', 'unit_selling_price', 'cost_value', 'retail_value'] as $key) {
            $data[$key] = (float) $data[$key];
        }
        $data['margin'] = $costs ? $data['retail_value'] - $data['cost_value'] : null;
        if (! $costs) {
            $data['unit_cost'] = null;
            $data['cost_value'] = null;
        }

        return $data;
    }
}
