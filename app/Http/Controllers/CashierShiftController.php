<?php

namespace App\Http\Controllers;

use App\Http\Requests\Shifts\CloseShiftRequest;
use App\Http\Requests\Shifts\OpenShiftRequest;
use App\Http\Requests\Shifts\StoreCashMovementRequest;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\LocationAccessService;
use App\Services\ShiftTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashierShiftController extends Controller
{
    public function __construct(private readonly CashierShiftService $shifts, private readonly LocationAccessService $locations, private readonly ShiftTransactionService $transactions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'location_id' => ['nullable', 'integer'], 'cashier_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['open', 'closed', 'pending_approval', 'approved'])],
            'variance' => ['nullable', Rule::in(['balanced', 'shortage', 'surplus'])],
        ]);
        $query = $this->shifts->accessibleQuery($user)->with(['location:id,name', 'cashier:id,name']);
        foreach (['location_id', 'cashier_id', 'status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        $timezone = $this->locations->business()->timezone;
        if (filled($filters['date_from'] ?? null)) {
            $query->where('opened_at', '>=', Carbon::parse($filters['date_from'], $timezone)->startOfDay());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('opened_at', '<=', Carbon::parse($filters['date_to'], $timezone)->endOfDay());
        }
        if (filled($filters['variance'] ?? null)) {
            $operator = match ($filters['variance']) {
                'shortage' => '<', 'surplus' => '>', default => '='
            };
            $query->where('variance', $operator, 0);
        }
        $active = $this->shifts->activeQuery($user)->with(['cashier:id,name', 'location:id,name'])->first();

        return Inertia::render('shifts/Index', [
            'shifts' => $query->latest('opened_at')->latest('id')->paginate(20)->withQueryString(),
            'activeShift' => $active, 'activeSummary' => $active !== null ? $this->shifts->summary($active) : null,
            'locations' => $this->locations->accessibleQuery($user)->orderBy('name')->get(['id', 'name', 'status']),
            'cashiers' => $user->can('shifts.view-all') ? User::query()->whereIn('id', $this->shifts->accessibleQuery($user)->select('cashier_id'))->orderBy('name')->get(['id', 'name']) : [],
            'filters' => $filters, 'currency' => $this->locations->business()->currency,
            'timezone' => $timezone,
            'historyOnly' => $request->routeIs('reports.shifts.history'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('shifts.open'), 403);

        return Inertia::render('shifts/Open', [
            'locations' => $this->locations->accessibleQuery($request->user())->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'cashierName' => $request->user()->name, 'currency' => $this->locations->business()->currency,
        ]);
    }

    public function store(OpenShiftRequest $request): RedirectResponse
    {
        $shift = $this->shifts->open($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shift opened.']);

        return $request->routeIs('pos.shifts.store')
            ? to_route('pos.index', ['location_id' => $shift->location_id])
            : to_route('shifts.show', $shift);
    }

    public function show(Request $request, CashierShift $shift): Response
    {
        return $this->details($request, $shift, 'shifts/Show');
    }

    public function movements(Request $request, CashierShift $shift): Response
    {
        return $this->details($request, $shift, 'shifts/Movements');
    }

    public function movement(Request $request, CashierShift $shift, CashMovement $movement): Response
    {
        $shift = $this->shifts->scoped($request->user(), $shift)->load(['cashier:id,name', 'location:id,name']);
        $movement = $shift->movements()->whereKey($movement->id)->with(['creator:id,name', 'reversal.creator:id,name', 'original.creator:id,name'])->withExists('reversal')->firstOrFail();

        return Inertia::render('shifts/CashMovement', [
            'shift' => $shift, 'movement' => $movement,
            'currency' => $shift->closing_summary['identity']['currency'] ?? $this->locations->business()->currency,
            'timezone' => $shift->closing_summary['identity']['timezone'] ?? $this->locations->business()->timezone,
            'events' => $shift->events()->where(fn ($events) => $events->where('details->movement_id', $movement->id)->orWhere('details->reversal_id', $movement->id))->with('actor:id,name')->orderBy('id')->get(),
        ]);
    }

    public function closing(Request $request, CashierShift $shift): Response|RedirectResponse
    {
        $shift = $this->shifts->scoped($request->user(), $shift);
        abort_unless($request->user()->can('shifts.close') && $shift->cashier_id === $request->user()->id, 403);
        if ($shift->closed_at !== null) {
            return to_route('shifts.show', $shift);
        }

        return $this->details($request, $shift, 'shifts/Close');
    }

    public function storeMovement(StoreCashMovementRequest $request, CashierShift $shift): RedirectResponse
    {
        $this->assertPosLocation($request, $shift);
        $this->shifts->movement($request->user(), $shift, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cash movement recorded.']);

        return $request->routeIs('pos.shifts.*')
            ? to_route('pos.index', ['location_id' => $shift->location_id])
            : back();
    }

    public function reverse(Request $request, CashierShift $shift, CashMovement $movement): RedirectResponse
    {
        abort_unless($request->user()->can('shifts.correct'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->shifts->reverse($request->user(), $shift, $movement, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cash movement reversed. Original record retained.']);

        return back();
    }

    public function close(CloseShiftRequest $request, CashierShift $shift): RedirectResponse
    {
        $this->assertPosLocation($request, $shift);
        $this->shifts->close($request->user(), $shift, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shift closed. Any variance awaits another authorized manager’s approval.']);

        if ($request->routeIs('pos.shifts.*')) {
            Inertia::flash('closedShift', ['id' => $shift->id, 'reference' => $shift->reference]);

            return to_route('pos.index', ['location_id' => $shift->location_id]);
        }

        return to_route('shifts.show', $shift);
    }

    public function approve(Request $request, CashierShift $shift): RedirectResponse
    {
        $this->shifts->approve($request->user(), $shift);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shift closure approved.']);

        return back();
    }

    private function details(Request $request, CashierShift $shift, string $page): Response
    {
        return DB::transaction(function () use ($request, $shift, $page): Response {
            $user = $request->user();
            $shift = $this->shifts->scoped($user, $shift)->load(['cashier:id,name', 'location:id,name']);
            $section = 'sales';
            if ($page === 'shifts/Show') {
                $data = $request->validate(['section' => ['nullable', Rule::in(['sales', 'payments', 'refunds', 'cash_movements', 'expenses', 'drops', 'closing'])]]);
                $section = $data['section'] ?? 'sales';
            }

            return Inertia::render($page, [
                'shift' => $shift, 'summary' => $this->shifts->summary($shift),
                ...($page === 'shifts/Show' ? ['section' => $section, 'transactions' => $this->transactions->transactions($user, $shift, $section)] : []),
                'movements' => $shift->movements()->with('creator:id,name')->withExists('reversal')->latest('id')->paginate(20, ['*'], 'movements_page')->withQueryString(),
                'events' => $shift->events()->with('actor:id,name')->latest('id')->paginate(20, ['*'], 'events_page')->withQueryString(),
                'currency' => $shift->closing_summary['identity']['currency'] ?? $this->locations->business()->currency,
                'timezone' => $shift->closing_summary['identity']['timezone'] ?? $this->locations->business()->timezone,
                'generatedAt' => now()->toIso8601String(),
                'canManage' => $shift->cashier_id === $user->id && $shift->status === 'open',
                'canApprove' => $user->can('shifts.approve') && $user->can('shifts.view-all') && $shift->cashier_id !== $user->id && $shift->status === 'pending_approval',
                'canCorrect' => $user->can('shifts.correct') && $user->can('shifts.view-all') && $shift->status === 'open',
            ]);
        });
    }

    private function assertPosLocation(Request $request, CashierShift $shift): void
    {
        if ($request->routeIs('pos.shifts.*')) {
            abort_unless($shift->location_id === $request->integer('location_id'), 404);
        }
    }
}
