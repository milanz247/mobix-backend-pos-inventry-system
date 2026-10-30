<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\ShiftEvent;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use stdClass;

/** @phpstan-type TransactionRow array{id: int, reference: string|null, at: string, amount: string|null, method: string, actor: string, status: string, description: string|null, url: string|null} */
class ShiftReportService
{
    public function __construct(private readonly SalesActivityService $activity, private readonly LocationAccessService $access) {}

    /** @return LengthAwarePaginator<int, TransactionRow> */
    public function transactions(User $user, CashierShift $shift, string $section): LengthAwarePaginator
    {
        $canOpenSale = $user->can('sales.view');
        if ($section === 'sales') {
            return $this->activity->query($user, $this->access->business(), $shift->location_id)
                ->where('cashier_shift_id', $shift->id)->leftJoin('users as actor', 'actor.id', '=', 'sales_activity.cashier_id')
                ->select('sales_activity.*', 'actor.name as actor_name')->orderByDesc('posted_at')->orderByDesc('sale_id')
                ->paginate(20)->withQueryString()->through(fn (stdClass $sale): array => $this->row([
                    'id' => (int) $sale->sale_id, 'reference' => $sale->sale_number.($sale->event_type === 'sale' ? '' : ' · '.$sale->event_type),
                    'at' => Carbon::parse($sale->posted_at)->toIso8601String(), 'amount' => (string) $sale->total,
                    'method' => $sale->event_type === 'replacement' ? 'exchange' : (SalePayment::query()->where('sale_id', $sale->sale_id)->where('cashier_shift_id', $shift->id)->pluck('method')->unique()->implode(' / ') ?: 'credit'),
                    'actor' => $sale->actor_name, 'status' => $sale->status,
                    'description' => $canOpenSale ? $sale->customer_name : 'Sale details require sales access',
                    'url' => $canOpenSale ? route('sales.show', $sale->sale_id) : null,
                ]));
        }
        if ($section === 'payments') {
            return SalePayment::query()->where('cashier_shift_id', $shift->id)->with(['sale:id,sale_number', 'receiver:id,name'])
                ->latest('paid_at')->latest('id')->paginate(20)->withQueryString()->through(fn (SalePayment $payment): array => $this->row([
                    'id' => $payment->id, 'reference' => 'PAY-'.$payment->id.' · '.$payment->sale->sale_number,
                    'at' => $payment->paid_at->toIso8601String(), 'amount' => $payment->amount, 'method' => $payment->method,
                    'actor' => $payment->receiver->name, 'status' => 'posted',
                    'description' => $payment->reference,
                    'url' => $canOpenSale ? route('sales.show', $payment->sale_id).'#payment-'.$payment->id : null,
                ]));
        }
        if ($section === 'refunds') {
            return SaleRefund::query()->where('cashier_shift_id', $shift->id)->with(['sale:id,sale_number', 'saleReturn:id,return_number,reason', 'processor:id,name'])
                ->latest('refunded_at')->latest('id')->paginate(20)->withQueryString()->through(fn (SaleRefund $refund): array => $this->row([
                    'id' => $refund->id, 'reference' => 'REF-'.$refund->id.' · '.$refund->saleReturn->return_number,
                    'at' => $refund->refunded_at->toIso8601String(), 'amount' => $refund->amount, 'method' => $refund->method,
                    'actor' => $refund->processor->name, 'status' => 'posted', 'description' => $refund->saleReturn->reason,
                    'url' => $canOpenSale ? route('sales.show', $refund->sale_id).'#refund-'.$refund->id : null,
                ]));
        }
        if ($section === 'closing') {
            return $shift->events()->whereIn('type', ['opened', 'closed', 'approved'])->with('actor:id,name')
                ->latest('id')->paginate(20)->withQueryString()->through(fn (ShiftEvent $event): array => $this->row([
                    'id' => $event->id, 'reference' => $shift->reference.' · '.$event->type,
                    'at' => $event->created_at->toIso8601String(),
                    'amount' => $this->closingAmount($event),
                    'method' => 'cash_drawer', 'actor' => $event->actor->name, 'status' => $event->type,
                    'description' => $event->type === 'closed' ? $shift->variance_reason : null,
                    'url' => $shift->closed_at !== null ? route('shifts.report', $shift) : null,
                ]));
        }
        $types = match ($section) {
            'expenses' => ['expense'], 'drops' => ['drop'], default => ['cash_in', 'cash_out']
        };

        return $shift->movements()->whereIn('type', $types)->with('creator:id,name')->withExists('reversal')
            ->latest('id')->paginate(20)->withQueryString()->through(fn (CashMovement $movement): array => $this->row([
                'id' => $movement->id, 'reference' => $movement->reference, 'at' => $movement->created_at->toIso8601String(),
                'amount' => $movement->amount, 'method' => 'cash_drawer', 'actor' => $movement->creator->name,
                'status' => $movement->reverses_id !== null ? 'reversal' : ($movement->getAttribute('reversal_exists') ? 'reversed' : 'completed'),
                'description' => $movement->type.' · '.$movement->reason,
                'url' => route('shifts.movement', [$shift->id, $movement->id]),
            ]));
    }

    /** @param TransactionRow $data
     * @return TransactionRow
     */
    private function row(array $data): array
    {
        return $data;
    }

    private function closingAmount(ShiftEvent $event): ?string
    {
        $amount = $event->details['actual_cash'] ?? $event->details['opening_cash'] ?? null;

        return is_string($amount) ? $amount : null;
    }
}
