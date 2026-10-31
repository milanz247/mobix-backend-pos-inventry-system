<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Services\LocationAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class StockOverviewReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access): Response
    {
        $business = $access->business();
        $locations = $access->accessibleQuery($request->user())->where('status', 'active')->get(['id', 'name', 'code']);
        $filters = $this->filters($request, $locations->pluck('id')->all());
        $rows = $this->rows($business->id, $locations->pluck('id')->all(), $filters);

        return Inertia::render('inventory/StockOverviewReport', [
            'rows' => $rows,
            'locations' => $locations,
            'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'currency' => $business->currency,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function excel(Request $request, LocationAccessService $access): StreamedResponse
    {
        $business = $access->business();
        $locations = $access->accessibleQuery($request->user())->where('status', 'active')->get(['id', 'name']);
        $filters = $this->filters($request, $locations->pluck('id')->all());
        $rows = $this->rows($business->id, $locations->pluck('id')->all(), $filters);
        $currency = $business->currency;

        $headers = ['Location', 'Product', 'Variant', 'SKU', 'Category', 'Brand', 'Tracking', 'Quantity', 'Unit cost ('.$currency.')', 'Unit selling price ('.$currency.')', 'Stock cost value ('.$currency.')', 'Stock retail value ('.$currency.')', 'Potential margin ('.$currency.')'];
        $matrix = [$headers];
        foreach ($rows as $row) {
            $matrix[] = [$row['location'], $row['product'], $row['variant'], $row['sku'], $row['category'], $row['brand'], $row['tracking'], $row['quantity'], $row['unit_cost'], $row['unit_selling_price'], $row['cost_value'], $row['retail_value'], $row['margin']];
        }
        $file = tempnam(sys_get_temp_dir(), 'stock-report-');
        abort_if($file === false, 500, __('Could not prepare the Excel report.'));
        $zip = new ZipArchive;
        abort_if($zip->open($file, ZipArchive::OVERWRITE) !== true, 500, __('Could not prepare the Excel report.'));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Stock overview" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($matrix as $rowIndex => $values) {
            $sheet .= '<row r="'.($rowIndex + 1).'">';
            foreach ($values as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $sheet .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } else {
                    $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $contents = file_get_contents($file);
        unlink($file);
        abort_if($contents === false, 500, __('Could not prepare the Excel report.'));

        return response()->streamDownload(fn () => print($contents), 'stock-overview-'.now()->format('Y-m-d-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(Request $request, LocationAccessService $access): StreamedResponse
    {
        $business = $access->business();
        $locationIds = $access->accessibleQuery($request->user())->where('status', 'active')->pluck('id')->all();
        $rows = $this->rows($business->id, $locationIds, $this->filters($request, $locationIds));
        $lines = [
            'STOCK OVERVIEW REPORT  |  '.$business->name.'  |  '.now()->format('Y-m-d H:i'),
            'Currency: '.$business->currency.'  |  Lines: '.$rows->count(),
            'Location     Product / SKU                          Qty       Unit cost    Unit price      Cost value   Selling value       Margin',
            str_repeat('-', 132),
        ];
        foreach ($rows as $row) {
            $label = $row['location'].' | '.$row['product'].' '.$row['variant'].' | '.$row['sku'];
            $lines[] = sprintf('%-50s %9s %13s %13s %15s %15s %13s', $this->pdfText($label, 50), number_format($row['quantity'], 3), number_format($row['unit_cost'], 2), number_format($row['unit_selling_price'], 2), number_format($row['cost_value'], 2), number_format($row['retail_value'], 2), number_format($row['margin'], 2));
        }
        $lines[] = str_repeat('-', 132);
        $lines[] = sprintf('%-50s %9s %13s %13s %15s %15s %13s', 'TOTAL', number_format($rows->sum('quantity'), 3), '', '', number_format($rows->sum('cost_value'), 2), number_format($rows->sum('retail_value'), 2), number_format($rows->sum('margin'), 2));
        $pdf = $this->makePdf($lines);

        return response()->streamDownload(fn () => print($pdf), 'stock-overview-'.now()->format('Y-m-d-His').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function pdfText(string $value, int $length): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return substr($ascii === false ? preg_replace('/[^\x20-\x7E]/', '?', $value) : $ascii, 0, $length);
    }

    /** @param list<string> $lines */
    private function makePdf(array $lines): string
    {
        $chunks = array_chunk($lines, 50);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];
        $kids = [];
        foreach ($chunks as $index => $pageLines) {
            $pageId = 4 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $stream = "BT\n/F1 6 Tf\n24 565 Td\n";
            foreach ($pageLines as $lineIndex => $line) {
                if ($lineIndex > 0) {
                    $stream .= "0 -10 Td\n";
                }
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $stream .= '('.$escaped.") Tj\n";
            }
            $stream .= "ET\n";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0)."\n";
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /** @param list<int> $locationIds
     *  @param array{search: string, location_id: int|null, category_id: int|null, brand_id: int|null} $filters
     *  @return array{search: string, location_id: int|null, category_id: int|null, brand_id: int|null}
     */
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

    /** @param list<int> $locationIds
     *  @param array{search: string, location_id: int|null, category_id: int|null, brand_id: int|null} $filters
     *  @return Collection<int, array<string, mixed>>
     */
    private function rows(int $businessId, array $locationIds, array $filters): Collection
    {
        $selectedLocations = $filters['location_id'] === null ? $locationIds : [$filters['location_id']];
        if ($selectedLocations === []) {
            return collect();
        }

        $quantityStocks = InventoryStock::query()
            ->with(['location:id,name', 'variant:id,product_id,name,sku,barcode,tracking_mode,cost_price,selling_price', 'variant.product:id,name,category_id,brand_id', 'variant.product.category:id,name', 'variant.product.brand:id,name'])
            ->where('business_id', $businessId)
            ->whereIn('location_id', $selectedLocations)
            ->whereHas('variant', fn (Builder $query) => $this->filterVariant($query, $filters))
            ->get()
            ->map(function (InventoryStock $stock): array {
                $variant = $stock->variant;
                $quantity = (float) $stock->quantity_on_hand;
                $cost = $quantity * (float) $variant->cost_price;
                $retail = $quantity * (float) $variant->selling_price;

                return $this->row($stock->location->name, $variant->product->name, $variant->name, $variant->sku, $variant->product->category?->name, $variant->product->brand?->name, 'Quantity based', $quantity, (float) $variant->cost_price, (float) $variant->selling_price, $cost, $retail);
            });

        $deviceQuery = DB::table('serialized_devices as d')
            ->join('product_variants as v', 'v.id', '=', 'd.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('locations as l', 'l.id', '=', 'd.location_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('d.business_id', $businessId)
            ->whereIn('d.location_id', $selectedLocations)
            ->where('d.status', 'in_stock')
            ->when($filters['search'] !== '', fn (QueryBuilder $query) => $query->where(fn (QueryBuilder $scope) => $scope->where('p.name', 'like', '%'.$filters['search'].'%')->orWhere('v.name', 'like', '%'.$filters['search'].'%')->orWhere('v.sku', 'like', '%'.$filters['search'].'%')->orWhere('v.barcode', 'like', '%'.$filters['search'].'%')))
            ->when($filters['category_id'] !== null, fn (QueryBuilder $query) => $query->where('p.category_id', $filters['category_id']))
            ->when($filters['brand_id'] !== null, fn (QueryBuilder $query) => $query->where('p.brand_id', $filters['brand_id']))
            ->selectRaw('d.location_id, l.name as location, v.id as variant_id, p.name as product, v.name as variant, v.sku, c.name as category, b.name as brand, COUNT(d.id) as quantity, SUM(d.unit_cost) as cost_value, SUM(COALESCE(d.unit_selling_price, v.selling_price)) as retail_value, AVG(d.unit_cost) as unit_cost, AVG(COALESCE(d.unit_selling_price, v.selling_price)) as unit_selling_price')
            ->groupBy('d.location_id', 'l.name', 'v.id', 'p.name', 'v.name', 'v.sku', 'c.name', 'b.name');

        $deviceRows = $deviceQuery->get()->map(fn (object $device): array => $this->row($device->location, $device->product, $device->variant, $device->sku, $device->category, $device->brand, 'Serialized', (float) $device->quantity, (float) $device->unit_cost, (float) $device->unit_selling_price, (float) $device->cost_value, (float) $device->retail_value));

        return $quantityStocks->concat($deviceRows)->sortBy([['location', 'asc'], ['product', 'asc'], ['variant', 'asc']])->values();
    }

    /** @param array{search: string, location_id: int|null, category_id: int|null, brand_id: int|null} $filters */
    private function filterVariant(Builder $query, array $filters): void
    {
        $query->where('tracking_mode', 'quantity_based')
            ->when($filters['search'] !== '', fn (Builder $variant) => $variant->where(function (Builder $scope) use ($filters): void {
                $scope->where('sku', 'like', '%'.$filters['search'].'%')->orWhere('barcode', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%')->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.$filters['search'].'%'));
            }))
            ->when($filters['category_id'] !== null, fn (Builder $variant) => $variant->whereHas('product', fn (Builder $product) => $product->where('category_id', $filters['category_id'])))
            ->when($filters['brand_id'] !== null, fn (Builder $variant) => $variant->whereHas('product', fn (Builder $product) => $product->where('brand_id', $filters['brand_id'])));
    }

    /** @return array<string, mixed> */
    private function row(string $location, string $product, string $variant, string $sku, ?string $category, ?string $brand, string $tracking, float $quantity, float $unitCost, float $unitSelling, float $costValue, float $retailValue): array
    {
        return [
            'location' => $location, 'product' => $product, 'variant' => $variant, 'sku' => $sku,
            'category' => $category ?? 'Uncategorised', 'brand' => $brand ?? '—', 'tracking' => $tracking,
            'quantity' => round($quantity, 3), 'unit_cost' => round($unitCost, 2), 'unit_selling_price' => round($unitSelling, 2),
            'cost_value' => round($costValue, 2), 'retail_value' => round($retailValue, 2), 'margin' => round($retailValue - $costValue, 2),
        ];
    }
}
