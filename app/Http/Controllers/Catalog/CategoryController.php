<?php

namespace App\Http\Controllers\Catalog;

use App\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryStatusRequest;
use App\Models\Business;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $search = $request->string('search')->trim()->toString();
        $status = CatalogStatus::tryFrom($request->string('status')->toString());

        $categories = $business->categories()
            ->withCount('products')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'status' => $category->status->value,
                'products_count' => $category->products_count,
            ]);

        return Inertia::render('catalog/categories/Index', [
            'categories' => $categories,
            'filters' => ['search' => $search, 'status' => $status->value ?? ''],
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Business::query()->where('setup_key', 'initial')->sole()->categories()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('categories.index');
    }

    public function edit(Category $category): Response
    {
        $category = Business::query()->where('setup_key', 'initial')->sole()->categories()->findOrFail($category->id);

        return Inertia::render('catalog/categories/Edit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'status' => $category->status->value,
            ],
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category = Business::query()->where('setup_key', 'initial')->sole()->categories()->findOrFail($category->id);
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return to_route('categories.index');
    }

    public function updateStatus(UpdateCategoryStatusRequest $request, Category $category): RedirectResponse
    {
        $category = Business::query()->where('setup_key', 'initial')->sole()->categories()->findOrFail($category->id);
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category status updated.')]);

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        DB::transaction(function () use ($category): void {
            $managedCategory = Business::query()->where('setup_key', 'initial')->sole()->categories()
                ->lockForUpdate()
                ->findOrFail($category->id);

            if ($managedCategory->products()->exists()) {
                throw ValidationException::withMessages([
                    'category' => __('This category cannot be deleted because it is assigned to products.'),
                ]);
            }

            $managedCategory->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return to_route('categories.index');
    }

    /** @return list<array{value: string, label: string}> */
    private function statuses(): array
    {
        return array_map(fn (CatalogStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], CatalogStatus::cases());
    }
}
