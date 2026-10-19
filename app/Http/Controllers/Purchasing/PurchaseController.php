<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseRequest;
use App\Http\Requests\Purchasing\UpdatePurchaseRequest;
use App\Models\Purchase;
use App\PurchasePaymentStatus;
use App\PurchaseStatus;
use App\Services\LocationAccessService;
use App\Services\PurchaseService;
use App\Support\PurchasePageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request, LocationAccessService $locationAccess): Response
    {
        $business = $locationAccess->business();
        $search = $request->string('search')->trim()->toString();
        $status = PurchaseStatus::tryFrom($request->string('status')->toString());
        $paymentStatus = PurchasePaymentStatus::tryFrom($request->string('payment_status')->toString());
        $locationIds = $locationAccess->accessibleQuery($request->user())->select('locations.id');
        $purchases = $business->purchases()->whereIn('location_id', $locationIds)
            ->with(['supplier:id,name', 'location:id,name,code'])
            ->withCount('lines')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', "%{$search}%"))))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($paymentStatus !== null, fn ($query) => $query->where('payment_status', $paymentStatus->value))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', (int) $request->input('location_id')))
            ->latest('invoice_date')->latest('id')->paginate(15)->withQueryString()
            ->through(fn (Purchase $purchase): array => [
                'id' => $purchase->id,
                'invoice_number' => $purchase->invoice_number,
                'invoice_date' => $purchase->invoice_date->toDateString(),
                'supplier' => $purchase->supplier->name,
                'location' => $purchase->location->name,
                'lines_count' => $purchase->lines_count,
                'total' => $purchase->total,
                'paid_total' => $purchase->paid_total,
                'status' => $purchase->status->value,
                'payment_status' => $purchase->payment_status->value,
            ]);

        return Inertia::render('purchasing/purchases/Index', [
            'purchases' => $purchases,
            'locations' => array_values($locationAccess->accessibleQuery($request->user())->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($location): array => ['id' => $location->id, 'name' => $location->name])->all()),
            'filters' => [
                'search' => $search,
                'status' => $status?->value ?? '',
                'payment_status' => $paymentStatus?->value ?? '',
                'location_id' => $request->input('location_id', ''),
            ],
            'statuses' => array_map(fn (PurchaseStatus $item): array => ['value' => $item->value, 'label' => str($item->value)->replace('_', ' ')->title()->toString()], PurchaseStatus::cases()),
            'paymentStatuses' => array_map(fn (PurchasePaymentStatus $item): array => ['value' => $item->value, 'label' => str($item->value)->replace('_', ' ')->title()->toString()], PurchasePaymentStatus::cases()),
            'currency' => $business->currency,
        ]);
    }

    public function create(Request $request, PurchasePageData $pageData): Response
    {
        return Inertia::render('purchasing/purchases/Create', $pageData->options($request->user()));
    }

    public function store(StorePurchaseRequest $request, PurchaseService $purchases): RedirectResponse
    {
        $purchase = $purchases->createDraft($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase draft saved.')]);

        return to_route('purchases.show', $purchase);
    }

    public function show(Purchase $purchase, PurchaseService $purchases, PurchasePageData $pageData): Response
    {
        $purchase = $purchases->scoped(request()->user(), $purchase);
        $purchase->load(['lines.variant.product', 'lines.devices.serializedDevice.location', 'payments.recorder']);

        return Inertia::render('purchasing/purchases/Show', [
            'purchase' => $pageData->purchase($purchase, request()->user()),
            'outstanding' => $purchases->outstandingAmount($purchase)->__toString(),
            'currency' => $purchase->business->currency,
            'paymentMethods' => [
                ['value' => 'cash', 'label' => 'Cash'],
                ['value' => 'bank_transfer', 'label' => 'Bank transfer'],
                ['value' => 'card', 'label' => 'Card'],
                ['value' => 'cheque', 'label' => 'Cheque'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    public function edit(Purchase $purchase, PurchaseService $purchases, PurchasePageData $pageData): Response
    {
        $purchase = $purchases->scoped(request()->user(), $purchase);
        if ($purchase->status !== PurchaseStatus::Draft) {
            abort(404);
        }

        return Inertia::render('purchasing/purchases/Edit', [
            ...$pageData->options(request()->user()),
            'purchase' => $pageData->purchase($purchase, request()->user()),
        ]);
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $purchase = $purchases->updateDraft($request->user(), $purchase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase draft updated.')]);

        return to_route('purchases.show', $purchase);
    }

    public function destroy(Request $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $purchases->deleteDraft($request->user(), $purchase);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase draft deleted.')]);

        return to_route('purchases.index');
    }

    public function post(Request $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $purchase = $purchases->post($request->user(), $purchase);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase invoice posted and stock received.')]);

        return to_route('purchases.show', $purchase);
    }
}
