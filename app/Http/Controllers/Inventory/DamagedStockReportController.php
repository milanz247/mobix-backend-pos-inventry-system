<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\DamagedStockListService;
use App\Services\LocationAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DamagedStockReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access, DamagedStockListService $listing): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'tracking_mode' => ['nullable', 'in:quantity_based,serialized'],
        ]);
        $user = $request->user();
        $business = $access->business();
        $query = $listing->query($user, $business, $filters);
        $showCosts = $user->can('sales.costs.view');
        $printMode = $request->boolean('print');
        $rowsQuery = $listing->rows($query);
        $rows = $printMode
            ? $rowsQuery->get()->map(fn (\stdClass $row): array => $listing->row($row, $showCosts))->all()
            : $rowsQuery->paginate(50)->withQueryString()->through(fn (\stdClass $row): array => $listing->row($row, $showCosts));

        return Inertia::render('inventory/DamagedStockReport', [
            'rows' => $rows,
            'printMode' => $printMode,
            'summary' => $listing->summary($query, $showCosts),
            'byLocation' => $listing->breakdown($query, 'branch', $showCosts),
            'locations' => $access->accessibleQuery($user)->orderBy('name')->get(['id', 'name']),
            'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'location_id' => $filters['location_id'] ?? '',
                'category_id' => $filters['category_id'] ?? '',
                'brand_id' => $filters['brand_id'] ?? '',
                'model_number' => $filters['model_number'] ?? '',
                'tracking_mode' => $filters['tracking_mode'] ?? '',
            ],
            'currency' => $business->currency,
            'canViewCosts' => $showCosts,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
