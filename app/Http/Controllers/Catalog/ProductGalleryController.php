<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ProductGalleryController extends Controller
{
    public function edit(Product $product): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $product = $business->products()->findOrFail($product->id);

        return Inertia::render('storefront/ProductGallery', ['product' => [
            'id' => $product->id, 'name' => $product->name,
            'images' => array_map(fn ($path) => Storage::disk('public')->url($path), $product->gallery_paths ?? []),
        ]]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $business->products()->findOrFail($product->id);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ]);
        $paths = [];
        try {
            foreach ($data['images'] as $image) {
                $path = $image->store('product-images', 'public');
                if ($path === false) {
                    throw new RuntimeException('Gallery image could not be stored.');
                }
                $paths[] = $path;
            }
            DB::transaction(function () use ($business, $product, $paths): void {
                Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
                $locked = $business->products()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $locked->gallery_paths = $paths;
                $locked->save();
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($paths);
            throw $exception;
        }

        // Replaced files are retained for now; storage cleanup is a separate operation.
        return back();
    }
}
