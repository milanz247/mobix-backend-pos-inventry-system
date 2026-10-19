<?php

namespace App\Services;

use App\LocationStatus;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(private readonly LocationAccessService $locations) {}

    /** @return Builder<CashierShift> */
    public function accessibleQuery(User $user): Builder
    {
        $query = CashierShift::query()->where('business_id', $this->locations->business()->id)
            ->whereIn('location_id', $this->locations->accessibleQuery($user)->select('locations.id'));
        if (! $user->can('shifts.view-all')) {
            $query->where('cashier_id', $user->id);
        }

        return $query;
    }

    public function scoped(User $user, CashierShift $shift): CashierShift
    {
        abort_unless($user->can('shifts.view'), 403);

        return $this->accessibleQuery($user)->findOrFail($shift->id);
    }

    /** @return Builder<CashierShift> */
    public function activeQuery(User $user): Builder
    {
        return $this->accessibleQuery($user)->where('cashier_id', $user->id)
            ->where('active_cashier_id', $user->id)->where('status', 'open')->whereNull('closed_at');
    }

    /** @return array<string, mixed> */
    public function posState(User $user, int $locationId): array
    {
        $this->locations->accessibleQuery($user)->where('status', LocationStatus::Active->value)->findOrFail($locationId);
        $shift = $user->can('shifts.view') ? $this->activeQuery($user)->with('location:id,name')->first() : null;
        $atLocation = $shift !== null && $shift->location_id === $locationId;

        return [
            'location_id' => $locationId,
            'shift' => $atLocation ? $shift->only(['id', 'reference', 'opening_cash', 'opened_at', 'status']) : null,
            'summary' => $atLocation ? $this->summary($shift) : null,
            'other_location' => $shift !== null && ! $atLocation ? ['id' => $shift->location_id, 'name' => $shift->location->name] : null,
            'permissions' => [
                'open' => $user->can('shifts.view') && $user->can('shifts.open'),
                'movements' => $user->can('shifts.view') && $user->can('shifts.movements'),
                'close' => $user->can('shifts.view') && $user->can('shifts.close'),
            ],
        ];
    }

    /**
     * Serialize POS writes, opening and closing on the cashier row, including when no shift exists.
     * Called inside the POS transaction before any sale or inventory locks.
     */
    public function activeId(User $user, int $locationId): ?int
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

        return $this->activeQuery($user)
            ->where('location_id', $locationId)->lockForUpdate()->value('id');
    }

    /** @param array<string, mixed> $data */
    public function open(User $user, array $data): CashierShift
    {
        abort_unless($user->can('shifts.open'), 403);
        try {
            return DB::transaction(function () use ($user, $data): CashierShift {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $location = $this->locations->accessibleQuery($user)->where('status', LocationStatus::Active->value)
                    ->whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
                $cash = $this->money((string) $data['opening_cash']);
                $existing = CashierShift::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->cashier_id !== $user->id || $existing->location_id !== $location->id
                        || $existing->opening_cash !== $cash || $existing->opening_note !== ($data['opening_note'] ?? null)) {
                        $this->fail('operation_key', 'This opening key was already used for different details.');
                    }

                    return $existing;
                }
                if (CashierShift::query()->where('active_cashier_id', $user->id)->exists()) {
                    $this->fail('location_id', 'Close your active shift before opening another shift.');
                }
                $shift = CashierShift::query()->create([
                    'business_id' => $location->business_id, 'location_id' => $location->id,
                    'cashier_id' => $user->id, 'active_cashier_id' => $user->id,
                    'reference' => 'SHIFT-'.Str::ulid(), 'operation_key' => $data['operation_key'],
                    'opening_cash' => $cash, 'opening_note' => $data['opening_note'] ?? null,
                    'opened_at' => now(), 'status' => 'open',
                ]);
                $this->event($shift, $user, 'opened', ['opening_cash' => $cash]);

                return $shift;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('operation_key', 'Shift opening conflicted with another request. Refresh and try again.');
        }
    }

    /** @param array<string, mixed> $data */
    public function movement(User $user, CashierShift $shift, array $data): CashMovement
    {
        abort_unless($user->can('shifts.movements'), 403);
        try {
            return DB::transaction(function () use ($user, $shift, $data): CashMovement {
                $shift = $this->locked($user, $shift);
                abort_unless($shift->cashier_id === $user->id, 403);
                $existing = CashMovement::query()->where('operation_key', $data['operation_key'])->first();
                if ($existing !== null) {
                    if ($existing->cashier_shift_id !== $shift->id || $existing->type !== $data['type'] || $existing->amount !== $this->money((string) $data['amount'])
                        || $existing->reason !== $data['reason'] || $existing->supporting_reference !== ($data['supporting_reference'] ?? null)) {
                        $this->fail('operation_key', 'This movement key was already used for different details.');
                    }

                    return $existing;
                }
                $this->assertOpen($shift);
                $this->locations->accessibleQuery($user)->where('status', LocationStatus::Active->value)->findOrFail($shift->location_id);
                $movement = $shift->movements()->create([
                    'created_by' => $user->id, 'reference' => 'CASH-'.Str::ulid(),
                    'operation_key' => $data['operation_key'], 'type' => $data['type'],
                    'amount' => $this->money((string) $data['amount']), 'reason' => $data['reason'],
                    'supporting_reference' => $data['supporting_reference'] ?? null,
                ]);
                $this->event($shift, $user, 'movement_recorded', ['movement_id' => $movement->id, 'type' => $movement->type, 'amount' => $movement->amount]);

                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('operation_key', 'This movement key was already used. Refresh and try again.');
        }
    }

    public function reverse(User $user, CashierShift $shift, CashMovement $movement, string $reason): void
    {
        abort_unless($user->can('shifts.correct') && $user->can('shifts.view-all'), 403);
        DB::transaction(function () use ($user, $shift, $movement, $reason): void {
            $shift = $this->locked($user, $shift);
            $this->assertOpen($shift);
            $movement = $shift->movements()->findOrFail($movement->id);
            if ($movement->reverses_id !== null) {
                $this->fail('reason', 'A reversal cannot itself be reversed.');
            }
            if ($shift->movements()->where('reverses_id', $movement->id)->exists()) {
                return;
            }
            $reversal = $shift->movements()->create([
                'created_by' => $user->id, 'reference' => 'REV-'.Str::ulid(), 'operation_key' => (string) Str::uuid(),
                'type' => $movement->type, 'amount' => $movement->amount, 'reason' => $reason, 'reverses_id' => $movement->id,
            ]);
            $this->event($shift, $user, 'movement_reversed', ['movement_id' => $movement->id, 'reversal_id' => $reversal->id, 'reason' => $reason]);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function summary(CashierShift $shift): array
    {
        if ($shift->closing_summary !== null) {
            return $shift->closing_summary;
        }
        $payments = SalePayment::query()->where('cashier_shift_id', $shift->id)
            ->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method')
            ->map(fn ($total): string => $this->money((string) $total))->all();
        $refunds = SaleRefund::query()->where('cashier_shift_id', $shift->id)
            ->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method')
            ->map(fn ($total): string => $this->money((string) $total))->all();
        $movements = array_fill_keys(['cash_in', 'cash_out', 'expense', 'drop'], '0.00');
        $totals = $shift->movements()->selectRaw('type, SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) as total')
            ->groupBy('type')->pluck('total', 'type');
        foreach ($totals as $type => $total) {
            $movements[$type] = $this->money((string) $total);
        }
        $cashSales = $payments['cash'] ?? '0.00';
        $cashRefunds = $refunds['cash'] ?? '0.00';
        $expected = BigDecimal::of($shift->opening_cash)->plus($cashSales)->plus($movements['cash_in'])
            ->minus($cashRefunds)->minus($movements['expense'])->minus($movements['cash_out'])->minus($movements['drop']);

        return [
            'opening_cash' => $shift->opening_cash, 'cash_sales' => $cashSales, 'cash_refunds' => $cashRefunds,
            'payments' => $payments, 'refunds' => $refunds, 'movements' => $movements,
            'expected_cash' => $this->money((string) $expected),
            'sales_count' => Sale::query()->where('cashier_shift_id', $shift->id)->count(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function close(User $user, CashierShift $shift, array $data): CashierShift
    {
        abort_unless($user->can('shifts.close'), 403);

        return DB::transaction(function () use ($user, $shift, $data): CashierShift {
            $shift = $this->locked($user, $shift);
            abort_unless($shift->cashier_id === $user->id, 403);
            if ($shift->closed_at !== null) {
                return $shift;
            }
            $this->assertOpen($shift);
            $summary = $this->summary($shift);
            $actual = $this->money((string) $data['actual_cash']);
            $variance = $this->money((string) BigDecimal::of($actual)->minus($summary['expected_cash']));
            if ($variance !== '0.00' && mb_strlen(trim((string) ($data['variance_reason'] ?? ''))) < 5) {
                $this->fail('variance_reason', 'Explain the cash shortage or surplus (at least 5 characters).');
            }
            $business = $this->locations->business();
            $closedAt = now();
            $summary['opened_at'] = $shift->opened_at->toIso8601String();
            $summary['closed_at'] = $closedAt->toIso8601String();
            $summary['identity'] = [
                'business' => $business->name, 'currency' => $business->currency, 'timezone' => $business->timezone,
                'location' => $shift->location->name, 'location_code' => $shift->location->code,
                'cashier' => $shift->cashier->name, 'closed_by' => $user->name,
            ];
            $shift->update([
                'status' => $variance === '0.00' ? 'closed' : 'pending_approval', 'active_cashier_id' => null,
                'closed_at' => $closedAt, 'closed_by' => $user->id,
                'expected_cash' => $summary['expected_cash'], 'actual_cash' => $actual,
                'variance' => $variance, 'variance_reason' => $data['variance_reason'] ?? null,
                'closing_note' => $data['closing_note'] ?? null, 'closing_summary' => $summary,
            ]);
            $this->event($shift, $user, 'closed', ['expected_cash' => $summary['expected_cash'], 'actual_cash' => $actual, 'variance' => $variance]);

            return $shift;
        }, 3);
    }

    public function approve(User $user, CashierShift $shift): void
    {
        abort_unless($user->can('shifts.approve') && $user->can('shifts.view-all'), 403);
        DB::transaction(function () use ($user, $shift): void {
            $shift = $this->locked($user, $shift);
            abort_if($shift->cashier_id === $user->id, 403, 'Another authorized manager must review this closure.');
            if ($shift->status === 'approved') {
                return;
            }
            if ($shift->status !== 'pending_approval') {
                $this->fail('shift', 'Only variance closures awaiting approval can be approved.');
            }
            $shift->update(['status' => 'approved', 'approved_by' => $user->id, 'approver_name' => $user->name, 'approved_at' => now()]);
            $this->event($shift, $user, 'approved', ['approver' => $user->name]);
        }, 3);
    }

    private function locked(User $user, CashierShift $shift): CashierShift
    {
        User::query()->whereKey($shift->cashier_id)->lockForUpdate()->firstOrFail();
        $this->scoped($user, $shift);

        return CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(CashierShift $shift): void
    {
        if ($shift->status !== 'open') {
            $this->fail('shift', 'This shift is closed. No new cash movements or corrections are allowed.');
        }
    }

    /** @param array<string, mixed> $details */
    private function event(CashierShift $shift, User $user, string $type, array $details): void
    {
        $shift->events()->create(['actor_id' => $user->id, 'type' => $type, 'details' => $details, 'created_at' => now()]);
    }

    private function money(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
