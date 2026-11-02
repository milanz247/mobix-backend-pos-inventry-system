<?php

namespace App\Http\Controllers\Catalog;

use App\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandStatusRequest;
use App\Models\Brand;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $search = $request->string('search')->trim()->toString();
        $status = CatalogStatus::tryFrom($request->string('status')->toString());

        $brands = $business->brands()
            ->withCount('products')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Brand $brand): array => [
                'id' => $brand->id,
                'name' => $brand->name,
                'description' => $brand->description,
                'show_on_homepage' => (bool) $brand->show_on_homepage,
                'homepage_order' => (int) $brand->homepage_order,
                'logo_url' => $brand->logo_path === null ? null : Storage::disk('public')->url($brand->logo_path),
                'status' => $brand->status->value,
                'products_count' => $brand->products_count,
            ]);

        return Inertia::render('catalog/brands/Index', [
            'brands' => $brands,
            'filters' => ['search' => $search, 'status' => $status->value ?? ''],
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(StoreBrandRequest $request): RedirectResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $attributes = $request->safe()->except('logo');
        $logoPath = $this->storeLogo($request->file('logo'));

        if ($logoPath !== null) {
            $attributes['logo_path'] = $logoPath;
        }

        try {
            $business->brands()->create($attributes);
        } catch (Throwable $exception) {
            if ($logoPath !== null) {
                Storage::disk('public')->delete($logoPath);
            }

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand created.')]);

        return to_route('brands.index');
    }

    public function edit(Brand $brand): Response
    {
        $brand = Business::query()->where('setup_key', 'initial')->sole()->brands()->findOrFail($brand->id);

        return Inertia::render('catalog/brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'description' => $brand->description,
                'show_on_homepage' => (bool) $brand->show_on_homepage,
                'homepage_order' => (int) $brand->homepage_order,
                'logo_url' => $brand->logo_path === null ? null : Storage::disk('public')->url($brand->logo_path),
                'status' => $brand->status->value,
            ],
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand = Business::query()->where('setup_key', 'initial')->sole()->brands()->findOrFail($brand->id);
        $attributes = $request->safe()->except('logo');
        $previousLogoPath = $brand->logo_path;
        $newLogoPath = $this->storeLogo($request->file('logo'));

        if ($newLogoPath !== null) {
            $attributes['logo_path'] = $newLogoPath;
        }

        try {
            $brand->update($attributes);
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($newLogoPath !== null
            && $previousLogoPath !== null
            && ! Brand::query()->where('logo_path', $previousLogoPath)->exists()) {
            Storage::disk('public')->delete($previousLogoPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand updated.')]);

        return to_route('brands.index');
    }

    public function updateStatus(UpdateBrandStatusRequest $request, Brand $brand): RedirectResponse
    {
        $brand = Business::query()->where('setup_key', 'initial')->sole()->brands()->findOrFail($brand->id);
        $brand->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand status updated.')]);

        return back();
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $logoPath = DB::transaction(function () use ($brand): ?string {
            $managedBrand = Business::query()->where('setup_key', 'initial')->sole()->brands()
                ->lockForUpdate()
                ->findOrFail($brand->id);

            if ($managedBrand->products()->exists()) {
                throw ValidationException::withMessages([
                    'brand' => __('This brand cannot be deleted because it is assigned to products.'),
                ]);
            }

            $logoPath = $managedBrand->logo_path;
            $managedBrand->delete();

            return $logoPath;
        });

        if ($logoPath !== null && ! Brand::query()->where('logo_path', $logoPath)->exists()) {
            Storage::disk('public')->delete($logoPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand deleted.')]);

        return to_route('brands.index');
    }

    /** @return list<array{value: string, label: string}> */
    private function statuses(): array
    {
        return array_map(fn (CatalogStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], CatalogStatus::cases());
    }

    private function storeLogo(?UploadedFile $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        $path = $logo->store('brand-logos', 'public');

        if ($path === false) {
            throw new RuntimeException(__('The brand logo could not be stored.'));
        }

        return $path;
    }
}
