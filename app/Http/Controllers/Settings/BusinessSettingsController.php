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
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = Business::query()->sole();

        return Inertia::render('settings/Business', [
            'business' => [
                'name' => $business->name,
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
        $request->business()->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Business settings updated.'),
        ]);

        return back();
    }
}
