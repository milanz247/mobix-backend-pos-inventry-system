<?php

namespace App\Http\Controllers\Settings;

use App\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\TimeFormat;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BusinessSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = Business::query()->sole();

        return Inertia::render('settings/Business', [
            'business' => [
                'name' => $business->name,
                'logo_url' => $business->logo_path === null
                    ? null
                    : Storage::disk('public')->url($business->logo_path),
                'business_type' => $business->business_type->value,
                'email' => $business->email,
                'phone' => $business->phone,
                'address' => $business->address,
                'city' => $business->city,
                'country' => $business->country,
                'currency' => $business->currency,
                'timezone' => $business->timezone,
                'time_format' => $business->time_format->value,
            ],
            'businessTypes' => array_map(
                fn (BusinessType $businessType): array => [
                    'value' => $businessType->value,
                    'label' => $businessType->label(),
                ],
                BusinessType::cases(),
            ),
            'timezones' => DateTimeZone::listIdentifiers(),
            'timeFormats' => array_map(
                fn (TimeFormat $timeFormat): array => [
                    'value' => $timeFormat->value,
                    'label' => $timeFormat->label(),
                ],
                TimeFormat::cases(),
            ),
        ]);
    }

    public function update(UpdateBusinessSettingsRequest $request): RedirectResponse
    {
        $business = $request->business();
        $validated = $request->safe()->except('logo');
        $previousLogoPath = $business->logo_path;

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('business-logos', 'public');

            if ($logoPath === false) {
                throw new RuntimeException(__('The business logo could not be stored.'));
            }

            $validated['logo_path'] = $logoPath;
        }

        $business->update($validated);

        if ($request->hasFile('logo') && $previousLogoPath !== null) {
            Storage::disk('public')->delete($previousLogoPath);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Business settings updated.'),
        ]);

        return back();
    }
}
