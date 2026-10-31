<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ProductActivityLog;
use App\Services\LocationAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductActivityController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'in:created,updated,deleted'],
        ]);
        $business = $access->business();
        $canViewCosts = $request->user()->can('sales.costs.view');
        $locations = $access->accessibleQuery($request->user())->select('locations.id');
        $search = trim((string) ($filters['search'] ?? ''));
        $logs = ProductActivityLog::query()->where('business_id', $business->id)
            ->where(fn ($query) => $query->whereNull('location_id')->orWhereIn('location_id', $locations))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('product_name', 'like', "%{$search}%")
                ->orWhere('actor_name', 'like', "%{$search}%")->orWhere('subject_id', 'like', "%{$search}%")))
            ->when(filled($filters['action'] ?? null), fn ($query) => $query->where('action', $filters['action']))
            ->with(['actor:id,name', 'location:id,name'])
            ->latest('created_at')->latest('id')->paginate(30)->withQueryString()
            ->through(fn (ProductActivityLog $log): array => [
                'id' => $log->id, 'action' => $log->action, 'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id, 'product_id' => $log->product_id,
                'product_name' => $log->product_name, 'actor' => $log->actor_id === null ? $log->actor_name : $log->actor->name,
                'location' => $log->location_id === null ? 'Business-wide' : $log->location->name, 'created_at' => $log->created_at->toIso8601String(),
                'changes' => collect($log->changes ?? [])->map(fn (array $change, string $field): array => [
                    'field' => $field,
                    'before' => ! $canViewCosts && $field === 'cost_price' ? 'Hidden' : $change['before'],
                    'after' => ! $canViewCosts && $field === 'cost_price' ? 'Hidden' : $change['after'],
                ])->values()->all(),
            ]);

        return Inertia::render('catalog/products/Activity', [
            'logs' => $logs,
            'filters' => ['search' => $search, 'action' => $filters['action'] ?? ''],
        ]);
    }
}
