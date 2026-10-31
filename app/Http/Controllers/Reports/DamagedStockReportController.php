<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\DamagedStockReportService;
use App\Services\LocationAccessService;
use App\Support\CsvDownload;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DamagedStockReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access, DamagedStockReportService $report): Response|StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'tracking_mode' => ['nullable', Rule::in(['quantity_based', 'serialized'])],
            'group_by' => ['nullable', Rule::in(['category', 'brand', 'branch'])],
            'view' => ['nullable', Rule::in(['items', 'category', 'branch'])],
        ]);
        $business = $access->business();
        $showCosts = $request->user()->can('sales.costs.view');
        $print = $request->routeIs('reports.damaged.print');

        return DB::transaction(function () use ($request, $access, $report, $filters, $business, $showCosts, $print): Response|StreamedResponse {
            $query = $report->query($request->user(), $business, $filters);
            $rows = $report->rows($query);
            if ($request->boolean('export') && ! $print) {
                $export = (function () use ($rows, $report, $showCosts): \Generator {
                    $header = ['Product', 'Variant', 'SKU', 'Barcode', 'Model', 'Category', 'Brand', 'Location', 'Unit', 'Tracking', 'Quantity', 'Devices'];
                    if ($showCosts) {
                        $header[] = 'Damaged value';
                    }
                    yield $header;
                    foreach ($rows->cursor() as $row) {
                        $item = $report->row($row, $showCosts);
                        $cells = array_map(fn ($key) => $item[$key], ['product', 'variant', 'sku', 'barcode', 'model_number', 'category', 'brand', 'location', 'unit', 'tracking_mode', 'quantity', 'devices']);
                        if ($showCosts) {
                            $cells[] = $item['value'];
                        }
                        yield $cells;
                    }
                })();

                return CsvDownload::make('damaged-stock.csv', $export);
            }
            $data = $print
                ? $rows->get()->map(fn (stdClass $row): array => $report->row($row, $showCosts))->all()
                : $rows->paginate(25)->withQueryString()->through(fn (stdClass $row): array => $report->row($row, $showCosts));

            return Inertia::render($print ? 'reports/DamagedPrint' : 'reports/Damaged', [
                'rows' => $data, 'summary' => $report->summary($query, $showCosts),
                'categoryGroups' => $this->paginateGroups($report->breakdown($query, 'category', $showCosts), $request, 'category_page'),
                'branchGroups' => $this->paginateGroups($report->breakdown($query, 'branch', $showCosts), $request, 'branch_page'),
                'view' => $filters['view'] ?? 'items',
                'groups' => $report->breakdown($query, $filters['group_by'] ?? 'category', $showCosts),
                'filters' => [...$filters, 'group_by' => $filters['group_by'] ?? 'category'],
                'locations' => $access->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']),
                'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
                'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
                'models' => ProductVariant::query()->where('business_id', $business->id)->whereNotNull('model_number')->where('model_number', '<>', '')->distinct()->orderBy('model_number')->pluck('model_number'),
                'business' => ['name' => $business->name, 'currency' => $business->currency],
                'showCosts' => $showCosts, 'canPrint' => $request->user()->can('damaged-reports.print'), 'generatedAt' => now()->format('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @param list<array<string, mixed>> $groups
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateGroups(array $groups, Request $request, string $pageName): LengthAwarePaginator
    {
        $page = max(1, $request->integer($pageName, 1));

        return (new LengthAwarePaginator(array_slice($groups, ($page - 1) * 20, 20), count($groups), 20, $page, ['path' => $request->url(), 'pageName' => $pageName]))->withQueryString();
    }
}
