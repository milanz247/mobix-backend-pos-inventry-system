<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\InventoryOperationType;
use App\Models\InventoryOperation;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryOperationReportController extends Controller
{
    public function __invoke(Request $request, string $type, LocationAccessService $access, InventoryPageData $pageData): Response
    {
        abort_unless(in_array($type, ['adjustments', 'transfers'], true), 404);
        $business = $access->business();
        $locationIds = $access->accessibleQuery($request->user())->pluck('id');
        $locationId = $request->integer('location_id') ?: null;
        $search = $request->string('search')->trim()->toString();
        $from = $request->date('from');
        $to = $request->date('to');
        $operationType = $type === 'adjustments' ? InventoryOperationType::Adjustment : InventoryOperationType::Transfer;

        $operations = InventoryOperation::query()
            ->with(['sourceLocation:id,name', 'destinationLocation:id,name', 'performer:id,name', 'lines.variant:id,name,sku,product_id', 'lines.variant.product:id,name'])
            ->where('business_id', $business->id)
            ->where('type', $operationType->value)
            ->where(function (Builder $query) use ($locationIds): void {
                $query->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds);
            })
            ->when($locationId !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->where('source_location_id', $locationId)->orWhere('destination_location_id', $locationId)))
            ->when($from, fn (Builder $query) => $query->whereDate('posted_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('posted_at', '<=', $to))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('reference', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('lines.variant', fn (Builder $variant) => $variant->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")));
            }))
            ->latest('posted_at')->paginate(25)->withQueryString()
            ->through(fn (InventoryOperation $operation): array => [
                'id' => $operation->id,
                'reference' => $operation->reference,
                'reason' => $operation->reason,
                'source' => $operation->sourceLocation?->name,
                'destination' => $operation->destinationLocation?->name,
                'actor' => $operation->performer->name,
                'posted_at' => $operation->posted_at->toIso8601String(),
                'lines' => $operation->lines->map(fn ($line): array => [
                    'product' => $line->variant->product->name,
                    'variant' => $line->variant->name,
                    'sku' => $line->variant->sku,
                    'quantity' => $line->quantity,
                ]),
            ]);

        return Inertia::render('inventory/OperationReport', [
            'type' => $type,
            'operations' => $operations,
            'locations' => $pageData->locations($request->user()),
            'filters' => ['search' => $search, 'location_id' => $locationId, 'from' => $from?->toDateString() ?? '', 'to' => $to?->toDateString() ?? ''],
        ]);
    }
}
