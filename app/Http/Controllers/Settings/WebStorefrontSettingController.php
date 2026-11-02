<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WebStorefrontSetting;
use App\PermissionName;
use App\Services\LocationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebStorefrontSettingController extends Controller
{
    public function edit(LocationAccessService $access): Response
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);
        $settings = WebStorefrontSetting::query()->where('business_id', $access->business()->id)->first();
        $announcement = $settings === null
            ? ['text' => 'Live catalog · Build a cart and request an indicative quote · Orders are not open yet', 'direction' => 'left', 'enabled' => true]
            : ['text' => $settings->announcement_text, 'direction' => $settings->announcement_direction, 'enabled' => $settings->announcement_enabled];

        return Inertia::render('settings/StorefrontWebSettings', ['announcement' => $announcement]);
    }

    public function update(Request $request, LocationAccessService $access): RedirectResponse
    {
        Gate::authorize(PermissionName::StorefrontManageHomepage->value);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:240'],
            'direction' => ['required', Rule::in(['left', 'right'])],
            'enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($access, $data): void {
            $business = $access->business();
            $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            WebStorefrontSetting::query()->updateOrCreate(
                ['business_id' => $business->id],
                ['announcement_text' => trim($data['text']), 'announcement_direction' => $data['direction'], 'announcement_enabled' => $data['enabled']],
            );
        }, 3);

        return to_route('storefront-web-settings.edit')->with('success', 'Web announcement settings saved.');
    }
}
