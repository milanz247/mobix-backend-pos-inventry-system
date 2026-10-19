<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\LocationAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locations, CashierShiftService $shifts): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'location_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'shift_reference' => ['nullable', 'string', 'max:48'],
            'source' => ['nullable', Rule::in(['cash_drawer'])],
            'status' => ['nullable', Rule::in(['completed', 'reversed'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount'])],
        ]);
        $user = $request->user();
        $business = $locations->business();
        if ($request->filled('location_id')) {
            $locations->accessibleQuery($user)->whereKey($request->integer('location_id'))->firstOrFail();
        }

        return DB::transaction(function () use ($request, $filters, $user, $business, $locations, $shifts): Response {
            $base = CashMovement::query()->whereIn('cashier_shift_id', $shifts->accessibleQuery($user)->select('id'))
                ->where('type', 'expense')->whereNull('reverses_id');
            $query = (clone $base)->with(['creator:id,name', 'shift.location:id,name'])->withExists('reversal');
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }
            if ($request->filled('user_id')) {
                $query->where('created_by', $filters['user_id']);
            }
            if ($request->filled('location_id')) {
                $query->whereHas('shift', fn (Builder $shift) => $shift->where('location_id', $filters['location_id']));
            }
            if ($request->filled('shift_reference')) {
                $query->whereHas('shift', fn (Builder $shift) => $shift->where('reference', $filters['shift_reference']));
            }
            if (($filters['status'] ?? '') === 'completed') {
                $query->whereDoesntHave('reversal');
            }
            if (($filters['status'] ?? '') === 'reversed') {
                $query->whereHas('reversal');
            }
            $net = (string) BigDecimal::of((string) (clone $query)->whereDoesntHave('reversal')->sum('amount'))->toScale(2);
            $sort = $filters['sort'] ?? 'newest';
            $query->orderBy($sort === 'amount' ? 'amount' : 'created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderByDesc('id');

            return Inertia::render('reports/Expenses', [
                'rows' => (clone $query)->paginate(25)->withQueryString()->through(fn (CashMovement $movement): array => [
                    'id' => $movement->id, 'reference' => $movement->reference, 'created_at' => $movement->created_at->toIso8601String(),
                    'location' => $movement->shift->location->name, 'category' => null, 'reason' => $movement->reason,
                    'amount' => $movement->amount, 'source' => 'Cash drawer', 'creator' => $movement->creator->name,
                    'shift' => $movement->shift->reference, 'status' => $movement->getAttribute('reversal_exists') ? 'reversed' : 'completed',
                    'url' => route('shifts.movement', [$movement->cashier_shift_id, $movement->id]),
                ]),
                'summary' => ['total_expenses' => $net, 'cash_expenses' => $net, 'records' => (clone $query)->count()],
                'filters' => $filters,
                'locations' => $locations->accessibleQuery($user)->orderBy('name')->get(['id', 'name']),
                'users' => User::query()->whereIn('id', $base->select('created_by'))->orderBy('name')->get(['id', 'name']),
                'currency' => $business->currency, 'timezone' => $business->timezone,
            ]);
        });
    }
}
