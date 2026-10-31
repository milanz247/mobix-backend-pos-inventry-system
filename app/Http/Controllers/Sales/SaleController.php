<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\SalePaymentStatus;
use App\SaleStatus;
use App\Services\CustomerAccountService;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use App\Support\SalePageData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request, LocationAccessService $locationAccess): Response
    {
        $business = $locationAccess->business();
        $filters = $request->validate([
            'overdue' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $paymentStatus = SalePaymentStatus::tryFrom((string) ($filters['payment_status'] ?? ''));
        $status = SaleStatus::tryFrom((string) ($filters['status'] ?? ''));
        $accessibleLocationIds = $locationAccess->accessibleQuery($request->user())->select('locations.id');
        $sales = app(CustomerAccountService::class)->balances($business, $request->user())->whereIn('location_id', $accessibleLocationIds)
            ->when($request->boolean('overdue'), fn ($q) => $q->where('balance_due', '>', 0)->where('due_date', '<', now($business->timezone)->toDateString()))
            ->when(! $request->user()->can('credit-sales.view'), fn (Builder $query): Builder => $query->where('is_credit', false))
            ->when($request->route('credit'), fn (Builder $query): Builder => $query->where('is_credit', true))
            ->with(['location:id,name,code', 'cashier:id,name'])->withCount('lines')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('sale_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('lines', fn (Builder $lines): Builder => $lines->where('sku_snapshot', 'like', "%{$search}%")
                        ->orWhere('barcode_snapshot', 'like', "%{$search}%")
                        ->orWhere('imei_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('components', fn (Builder $components): Builder => $components->where('imei_snapshot', 'like', "%{$search}%")));
            }))
            ->when(! empty($filters['date_from']), fn (Builder $query): Builder => $query->whereDate('posted_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $query): Builder => $query->whereDate('posted_at', '<=', $filters['date_to']))
            ->when(! empty($filters['location_id']), fn (Builder $query): Builder => $query->where('location_id', (int) $filters['location_id']))
            ->when(! empty($filters['cashier_id']), fn (Builder $query): Builder => $query->where('cashier_id', (int) $filters['cashier_id']))
            ->when($paymentStatus !== null, fn (Builder $query): Builder => $query->where('payment_status', $paymentStatus->value))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->latest('posted_at')->latest('id')->paginate(15)->withQueryString()->through(fn (Sale $sale): array => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'source' => $sale->source->label(),
                'posted_at' => $sale->posted_at->format('Y-m-d H:i'),
                'customer' => $sale->customer_name ?: __('Walk-in customer'),
                'cashier' => $sale->cashier->name,
                'location' => $sale->location->name,
                'lines_count' => $sale->lines_count,
                'outstanding' => app(SaleService::class)->outstanding($sale)->__toString(),
                'paid_total' => $sale->paid_total,
                'total' => $sale->total,
                'payment_status' => $sale->payment_status->value,
                'status' => $sale->status->value,
            ]);

        $cashierIds = $business->sales()->whereIn('location_id', $accessibleLocationIds)->select('cashier_id')->distinct();

        return Inertia::render('sales/Index', [
            'creditOnly' => (bool) $request->route('credit'),
            'sales' => $sales,
            'locations' => $locationAccess->accessibleQuery($request->user())->orderBy('name')->get(['id', 'name']),
            'cashiers' => User::query()->whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'overdue' => $request->boolean('overdue') ? 1 : 0,
                'search' => $search,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'location_id' => $filters['location_id'] ?? '',
                'cashier_id' => $filters['cashier_id'] ?? '',
                'payment_status' => $paymentStatus !== null ? $paymentStatus->value : '',
                'status' => $status !== null ? $status->value : '',
            ],
            'paymentStatuses' => array_map(fn (SalePaymentStatus $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], SalePaymentStatus::cases()),
            'statuses' => array_map(fn (SaleStatus $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], SaleStatus::cases()),
            'currency' => $business->currency,
        ]);
    }

    public function show(Request $request, Sale $sale, SaleService $sales, SalePageData $pageData): Response
    {
        $sale = $sales->scoped($request->user(), $sale);

        return Inertia::render('sales/Show', [
            'sale' => $pageData->sale($sale, $request->user()->can('sales.costs.view')),
            'currency' => $sale->business->currency,
            'outstanding' => $sales->outstanding($sale)->__toString(),
            'canAddItems' => (bool) $sale->is_credit && $sale->customer_id !== null && $sale->status === SaleStatus::Completed && $sale->payment_status !== SalePaymentStatus::Paid && $request->user()->can('sales.credit') && $request->user()->can('credit-sales.add-items') && $request->user()->can('sales.pos') && $request->user()->can('sales.create'),
            'canLinkCustomer' => $sale->customer_id === null && $request->user()->can('sales.customer-link'),
            'canPrintReceipt' => $request->user()->can('sales-receipts.print'),
            'canPrintReturn' => $request->user()->can('return-receipts.print'),
            'canRecordPayment' => $request->user()->can('sale-payments.create'),
            'canReturn' => $request->user()->can('sale-returns.create'),
            'canRefund' => $request->user()->can('sale-refunds.create'),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function linkCustomer(Request $request, Sale $sale, SaleService $sales): RedirectResponse
    {
        $sale = $sales->scoped($request->user(), $sale);
        abort_unless($request->user()->can('sales.customer-link'), 403);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $sale->business_id)],
        ]);

        DB::transaction(function () use ($sale, $data): void {
            $lockedSale = Sale::query()->where('business_id', $sale->business_id)->lockForUpdate()->findOrFail($sale->id);
            if ($lockedSale->customer_id !== null) {
                throw ValidationException::withMessages(['customer_id' => __('This invoice is already linked to a customer.')]);
            }
            $customer = Customer::query()->where('business_id', $sale->business_id)->lockForUpdate()->findOrFail((int) $data['customer_id']);
            $lockedSale->update(['customer_id' => $customer->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Historical invoice linked to customer.')]);

        return back();
    }

    public function receipt(Request $request, Sale $sale, SaleService $sales, SalePageData $pageData): Response
    {
        $sale = $sales->scoped(request()->user(), $sale);

        return Inertia::render('sales/Receipt', [
            'sale' => $pageData->sale($sale),
            'business' => $pageData->business($sale->business),
            'autoPrint' => $request->boolean('autoprint'),
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function paymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => __('Cash')],
            ['value' => 'card', 'label' => __('Card')],
            ['value' => 'bank_transfer', 'label' => __('Bank transfer')],
            ['value' => 'other', 'label' => __('Other')],
        ];
    }
}
