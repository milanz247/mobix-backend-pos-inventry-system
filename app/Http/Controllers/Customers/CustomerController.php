<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SaleLine;
use App\Services\CustomerAccountService;
use App\Services\LocationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, LocationAccessService $access): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($filters['search'] ?? '');
        $phones = Customer::phoneSearchTerms($search);

        return Inertia::render('customers/Index', [
            'customers' => Customer::query()->withExists('sales')->where('business_id', $access->business()->id)
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search, $phones): void {
                    $q->where('name', 'like', "%{$search}%");
                    foreach ($phones as $phone) {
                        $q->orWhere('phone', 'like', "%{$phone}%");
                    }
                }))
                ->orderBy('name')->paginate(20)->withQueryString()->through(fn (Customer $customer): array => [...$customer->toArray(), 'can_delete' => ! $customer->getAttribute('sales_exists')]),
            'search' => $search, 'currency' => $access->business()->currency,
            'canCreate' => $request->user()->can('customers.create'),
            'canUpdate' => $request->user()->can('customers.update'),
            'canDelete' => $request->user()->can('customers.delete'),
        ]);
    }

    public function search(Request $request, LocationAccessService $access): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($filters['search'] ?? '');
        $phones = Customer::phoneSearchTerms($search);

        return response()->json(Customer::query()->where('business_id', $access->business()->id)
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search, $phones): void {
                $q->where('name', 'like', "%{$search}%");
                foreach ($phones as $phone) {
                    $q->orWhere('phone', 'like', "%{$phone}%");
                }
            }))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email', 'credit_limit', 'credit_days']));
    }

    public function show(Request $request, Customer $customer, LocationAccessService $access, CustomerAccountService $accounts): Response
    {
        abort_unless($customer->business_id === $access->business()->id, 404);
        $view = $request->validate(['view' => ['nullable', Rule::in(['purchases', 'credit'])]])['view'] ?? 'purchases';
        $sales = $accounts->balances($access->business(), $request->user())->where('customer_id', $customer->id);
        $today = now($access->business()->timezone)->toDateString();
        $aging = [];
        foreach (['not_due' => [null, 0], '1_30' => [1, 30], '31_60' => [31, 60], '61_90' => [61, 90], 'over_90' => [91, null]] as $name => [$min, $max]) {
            $query = (clone $sales)->where('balance_due', '>', 0);
            if ($min === null) {
                $query->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today));
            } else {
                $query->where('due_date', '<=', now($access->business()->timezone)->subDays($min)->toDateString());
                if ($max !== null) {
                    $query->where('due_date', '>=', now($access->business()->timezone)->subDays($max)->toDateString());
                }
            }
            $aging[$name] = (string) $query->sum('balance_due');
        }

        return Inertia::render('customers/Show', [
            'customer' => $customer, 'currency' => $access->business()->currency,
            'canUpdate' => $request->user()->can('customers.update'),
            'canViewSales' => $request->user()->can('sales.view'),
            'outstanding' => (string) (clone $sales)->sum('balance_due'), 'aging' => $aging,
            'view' => $view,
            'purchases' => SaleLine::query()->whereIn('sale_id', (clone $sales)->select('sales.id'))
                ->with(['sale.location:id,name'])->withSum(['returnLines as returned_quantity' => fn ($q) => $q->where('is_component', false)], 'quantity')
                ->latest('id')->paginate(20, ['*'], 'purchases_page')->withQueryString()->through(fn ($line): array => [
                    'id' => $line->id, 'invoice_id' => $line->sale_id, 'invoice' => $line->sale->sale_number,
                    'date' => $line->sale->posted_at->toIso8601String(), 'branch' => $line->sale->location->name,
                    'product' => $line->product_name_snapshot, 'variant' => $line->variant_name_snapshot,
                    'sku' => $line->sku_snapshot, 'imei' => $line->imei_snapshot, 'quantity' => $line->quantity,
                    'returned' => $line->getAttribute('returned_quantity') ?? '0.000', 'total' => $line->total,
                ]),
            'sales' => $sales->when($view === 'credit', fn ($q) => $q->where('is_credit', true))->with(['location:id,name', 'payments', 'returns.refunds'])->latest('posted_at')->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request, LocationAccessService $access): RedirectResponse
    {
        $data = $this->validated($request, $access);
        $customer = Customer::query()->create(['business_id' => $access->business()->id, ...$data]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer saved.']);

        return to_route('customers.index');
    }

    public function update(Request $request, Customer $customer, LocationAccessService $access): RedirectResponse
    {
        abort_unless($customer->business_id === $access->business()->id, 404);
        $customer->update($this->validated($request, $access, $customer));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer updated.']);

        return back();
    }

    public function destroy(Request $request, Customer $customer, LocationAccessService $access): RedirectResponse
    {
        abort_unless($customer->business_id === $access->business()->id, 404);
        DB::transaction(function () use ($customer): void {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($locked->sales()->exists()) {
                throw ValidationException::withMessages(['customer' => 'Customers with invoice history cannot be deleted.']);
            }
            $locked->delete();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer deleted.']);

        return to_route('customers.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, LocationAccessService $access, ?Customer $customer = null): array
    {
        $request->merge(['phone' => Customer::normalizePhone((string) $request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'regex:/^\+?[0-9]{7,15}$/', Rule::unique('customers')->where('business_id', $access->business()->id)->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:254'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'credit_days' => ['required', 'integer', 'between:0,365'],
        ]);
        if (Customer::query()->where('business_id', $access->business()->id)->whereIn('phone', Customer::phoneAliases($data['phone']))
            ->when($customer !== null, fn ($q) => $q->whereKeyNot($customer->id))->exists()) {
            throw ValidationException::withMessages(['phone' => 'A customer with this phone number already exists.']);
        }

        return $data;
    }
}
