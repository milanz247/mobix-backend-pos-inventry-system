<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WebHomeBanner;
use App\PermissionName;
use App\Services\LocationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebHomeBannerController extends Controller
{
    public function edit(LocationAccessService $access): Response
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);

        return Inertia::render('settings/StorefrontHomepage', ['banners' => WebHomeBanner::query()
            ->where('business_id', $access->business()->id)->latest('id')->get()
            ->map(fn (WebHomeBanner $banner): array => [
                'id' => $banner->id,
                'headline' => $banner->headline,
                'eyebrow' => $banner->eyebrow,
                'description' => $banner->description,
                'button_text' => $banner->button_text,
                'button_href' => $banner->button_href,
                'image_alt' => $banner->image_alt,
                'image_url' => $banner->image_path ? Storage::disk('public')->url($banner->image_path) : null,
                'is_active' => $banner->is_active,
                'updated_at' => $banner->updated_at?->toDateTimeString(),
            ])->all()]);
    }

    public function store(Request $request, LocationAccessService $access): RedirectResponse
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);
        $data = $this->validated($request, true);
        $newPath = $this->resizeImage($request);
        $data['image_path'] = $newPath;
        try {
            DB::transaction(function () use ($access, $data): void {
                $business = $access->business();
                $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();
                WebHomeBanner::query()->create(['business_id' => $business->id, ...$data, 'is_active' => false]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($newPath);
            throw $error;
        }

        return to_route('storefront-homepage.edit')->with('success', 'Banner created. Activate it when ready.');
    }

    public function update(Request $request, LocationAccessService $access, WebHomeBanner $banner): RedirectResponse
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);
        $this->ownedBanner($access, $banner);
        $data = $this->validated($request, false);
        $newPath = $request->hasFile('image') ? $this->resizeImage($request) : null;
        $previousPath = null;
        try {
            DB::transaction(function () use ($access, $banner, $data, $newPath, &$previousPath): void {
                $business = $access->business();
                $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();
                $locked = WebHomeBanner::query()->where('business_id', $business->id)->whereKey($banner->id)->lockForUpdate()->firstOrFail();
                if ($newPath !== null) {
                    $previousPath = $locked->image_path;
                    $data['image_path'] = $newPath;
                }
                $locked->update($data);
            }, 3);
        } catch (\Throwable $error) {
            if ($newPath !== null) {
                Storage::disk('public')->delete($newPath);
            }
            throw $error;
        }
        if ($previousPath !== null && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return to_route('storefront-homepage.edit')->with('success', 'Banner updated.');
    }

    public function status(Request $request, LocationAccessService $access, WebHomeBanner $banner): RedirectResponse
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);
        $this->ownedBanner($access, $banner);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($access, $banner, $data): void {
            $business = $access->business();
            $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $locked = WebHomeBanner::query()->where('business_id', $business->id)->whereKey($banner->id)->lockForUpdate()->firstOrFail();
            if ($data['is_active']) {
                if (! $locked->image_path) {
                    throw ValidationException::withMessages(['image' => 'Upload an image before activating this banner.']);
                }
                WebHomeBanner::query()->where('business_id', $business->id)->where('is_active', true)->update(['is_active' => false]);
            }
            $locked->update(['is_active' => (bool) $data['is_active']]);
        }, 3);

        return to_route('storefront-homepage.edit')->with('success', $data['is_active'] ? 'Banner activated.' : 'Banner deactivated.');
    }

    private function ownedBanner(LocationAccessService $access, WebHomeBanner $banner): void
    {
        abort_unless((int) $banner->business_id === (int) $access->business()->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:150'],
            'eyebrow' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'button_text' => ['nullable', 'string', 'max:60', 'required_with:button_href'],
            'button_href' => ['nullable', 'string', 'max:180', 'required_with:button_text', Rule::in(['/shop', '/cart'])],
            'image_alt' => ['nullable', 'string', 'max:180'],
            'image' => [$creating ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        unset($data['image']);

        return $data;
    }

    private function resizeImage(Request $request): string
    {
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['image' => 'Image processing is unavailable on this server.']);
        }
        $file = $request->file('image');
        $size = $file ? @getimagesize($file->getRealPath()) : false;
        if ($size === false || $size[0] < 800 || $size[1] < 450 || $size[0] > 6000 || $size[1] > 4000 || $size[0] * $size[1] > 20000000) {
            throw ValidationException::withMessages(['image' => 'Use an image at least 800 × 450 pixels and no larger than 6000 × 4000 pixels (20 megapixels).']);
        }
        $source = match ($size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
            default => false,
        };
        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'The image could not be read.']);
        }
        try {
            $scale = min(1, 1920 / $size[0], 1080 / $size[1]);
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
            $output = imagecreatetruecolor($width, $height);
            imagealphablending($output, false);
            imagesavealpha($output, true);
            $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
            if ($transparent === false) {
                throw ValidationException::withMessages(['image' => 'The image could not be processed.']);
            }
            imagefill($output, 0, 0, $transparent);
            imagecopyresampled($output, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            ob_start();
            $encoded = imagewebp($output, null, 82);
            $bytes = ob_get_clean();
            imagedestroy($output);
            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw ValidationException::withMessages(['image' => 'The image could not be processed.']);
            }
            $path = 'storefront-banners/'.Str::random(40).'.webp';
            if (! Storage::disk('public')->put($path, $bytes)) {
                throw ValidationException::withMessages(['image' => 'The image could not be stored.']);
            }

            return $path;
        } finally {
            imagedestroy($source);
        }
    }
}
