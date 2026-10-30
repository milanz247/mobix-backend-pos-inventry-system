<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\DamagedStockReportService;
use App\Services\LocationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class DamagedStockReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access, DamagedStockReportService $report): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'tracking_mode' => ['nullable', Rule::in(['quantity_based', 'serialized'])],
            'group_by' => ['nullable', Rule::in(['category', 'brand'])],
        ]);
        $business = $access->business();
        $showCosts = $request->user()->can('sales.costs.view');
        $print = $request->routeIs('reports.damaged.print');

        return DB::transaction(function () use ($request, $access, $report, $filters, $business, $showCosts, $print): Response {
            $query = $report->query($request->user(), $business, $filters);
            $rows = $report->rows($query);
            $data = $print
                ? $rows->get()->map(fn (stdClass $row): array => $report->row($row, $showCosts))->all()
                : $rows->paginate(25)->withQueryString()->through(fn (stdClass $row): array => $report->row($row, $showCosts));

            return Inertia::render($print ? 'reports/DamagedPrint' : 'reports/Damaged', [
                'rows' => $data, 'summary' => $report->summary($query, $showCosts),
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
}
