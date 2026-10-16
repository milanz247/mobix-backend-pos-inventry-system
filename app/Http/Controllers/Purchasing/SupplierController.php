<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\Supplier;
use App\PurchaseStatus;
use App\Services\LocationAccessService;
use App\SupplierStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::query()->sole();
        $search = $request->string('search')->trim()->toString();
        $status = SupplierStatus::tryFrom($request->string('status')->toString());
        $suppliers = $business->suppliers()->withCount('purchases')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'city' => $supplier->city,
                'status' => $supplier->status->value,
                'purchases_count' => $supplier->purchases_count,
            ]);

        return Inertia::render('purchasing/suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => ['search' => $search, 'status' => $status?->value ?? ''],
            'statuses' => array_map(fn (SupplierStatus $item): array => ['value' => $item->value, 'label' => $item->label()], SupplierStatus::cases()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('purchasing/suppliers/Create');
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Business::query()->sole()->suppliers()->create($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier created.')]);

        return to_route('suppliers.show', $supplier);
    }

    public function show(Supplier $supplier, LocationAccessService $locationAccess): Response
    {
        $supplier = Business::query()->sole()->suppliers()->findOrFail($supplier->id);
        $allowedLocationIds = $locationAccess->accessibleQuery(request()->user())->select('locations.id');
        $purchases = $supplier->purchases()->whereIn('location_id', $allowedLocationIds)
            ->with('location:id,name,code')->latest('invoice_date')->limit(10)->get()
            ->map(fn (Purchase $purchase): array => [
                'id' => $purchase->id,
                'invoice_number' => $purchase->invoice_number,
                'invoice_date' => $purchase->invoice_date->toDateString(),
                'location' => $purchase->location->name,
                'total' => $purchase->total,
                'status' => $purchase->status->value,
                'payment_status' => $purchase->payment_status->value,
            ]);

        return Inertia::render('purchasing/suppliers/Show', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'alternate_phone' => $supplier->alternate_phone,
                'address' => $supplier->address,
                'city' => $supplier->city,
                'notes' => $supplier->notes,
                'status' => $supplier->status->value,
            ],
            'purchases' => $purchases,
        ]);
    }

    public function edit(Supplier $supplier): Response
    {
        $supplier = Business::query()->sole()->suppliers()->findOrFail($supplier->id);

        return Inertia::render('purchasing/suppliers/Edit', ['supplier' => $supplier]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier = Business::query()->sole()->suppliers()->findOrFail($supplier->id);
        $supplier->update($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier updated.')]);

        return to_route('suppliers.show', $supplier);
    }

    public function updateStatus(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(SupplierStatus::class)]]);
        $supplier = Business::query()->sole()->suppliers()->findOrFail($supplier->id);
        if ($validated['status'] === SupplierStatus::Inactive->value
            && $supplier->purchases()->where('status', PurchaseStatus::Draft->value)->exists()) {
            return back()->withErrors(['status' => __('This supplier has draft purchases. Post or update them before deactivating the supplier.')]);
        }
        $supplier->update($validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier status updated.')]);

        return back();
    }
}
