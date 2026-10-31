<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\LocationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'location_id' => ['nullable', 'integer'],
            'actor_id' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:80'], 'session' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $business = $access->business();
        $user = $request->user();
        $allBranches = $access->hasAllLocationAccess($user, $business);
        $base = ActivityLog::query()->where('business_id', $business->id)->where(function ($q) use ($access, $user, $allBranches): void {
            $q->whereIn('location_id', $access->accessibleQuery($user)->select('locations.id'));
            if ($allBranches) {
                $q->orWhereNull('location_id');
            }
        });
        if ($request->routeIs('reports.product-activity')) {
            $base->whereNotNull('product_id');
        }
        $query = clone $base;
        foreach (['location_id', 'actor_id', 'product_id', 'action'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['session'] ?? null)) {
            $query->where('session_fingerprint', $filters['session']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'], $business->timezone)->startOfDay()->utc());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'], $business->timezone)->endOfDay()->utc());
        }
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('subject_name', 'like', "%{$search}%")->orWhere('product_name', 'like', "%{$search}%")->orWhere('actor_name', 'like', "%{$search}%"));
        }
        $showCosts = $user->can('sales.costs.view');
        $locations = $access->accessibleQuery($user)->pluck('name', 'id');

        return Inertia::render('reports/Activity', [
            'productActivity' => $request->routeIs('reports.product-activity'),
            'rows' => $query->latest('id')->paginate(25)->withQueryString()->through(fn (ActivityLog $log): array => [
                'id' => $log->getKey(), 'date' => Carbon::parse($log->getRawOriginal('created_at'), 'UTC')->toIso8601String(),
                'actor' => $log->getAttribute('actor_name'), 'action' => $log->getAttribute('action'),
                'subject' => $log->getAttribute('subject_type'), 'name' => $log->getAttribute('subject_name'),
                'product_id' => $log->getAttribute('product_id'), 'product' => $log->getAttribute('product_name'),
                'branch' => $locations->get($log->getAttribute('location_id'), 'Business / system'),
                'route' => $log->getAttribute('route'), 'method' => $log->getAttribute('method'),
                'session' => $log->getAttribute('session_fingerprint'), 'ip' => $log->getAttribute('ip_address'),
                'changes' => $this->visible($log->getAttribute('changes') ?? [], $showCosts, str_starts_with((string) $log->getAttribute('subject_type'), 'Purchase')),
            ]),
            'summary' => ['events' => (clone $query)->count(), 'actors' => (clone $query)->distinct()->count('actor_id'), 'sessions' => (clone $query)->distinct()->count('session_fingerprint')],
            'filters' => $filters, 'locations' => $access->accessibleQuery($user)->orderBy('name')->get(['id', 'name']),
            'actors' => User::query()->whereIn('id', (clone $base)->select('actor_id'))->orderBy('name')->get(['id', 'name']),
            'actions' => (clone $base)->select('action')->distinct()->orderBy('action')->pluck('action'),
            'timezone' => $business->timezone,
        ]);
    }

    /** @param array<array-key, mixed> $changes
     * @return array<array-key, mixed>
     */
    private function visible(array $changes, bool $costs, bool $purchase = false): array
    {
        $result = [];
        foreach ($changes as $key => $value) {
            if (! $costs && (preg_match('/cost|profit|margin/i', (string) $key) || ($purchase && preg_match('/amount|total|price|balance/i', (string) $key)))) {
                continue;
            }
            $result[$key] = is_array($value) ? $this->visible($value, $costs, $purchase) : $value;
        }

        return $result;
    }
}
