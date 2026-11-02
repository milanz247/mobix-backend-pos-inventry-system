<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ApplyBusinessPreferences
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->first(['name', 'logo_path', 'timezone', 'time_format']);
        $businessName = null;
        $businessLogoUrl = null;
        $timezone = config()->string('app.timezone');
        $timeFormat = config()->string('app.time_format');

        if ($business !== null) {
            $businessName = $business->name;
            $businessLogoUrl = $business->logo_path === null
                ? null
                : Storage::disk('public')->url($business->logo_path);
            $timezone = $business->timezone;
            $timeFormat = $business->time_format->value;
        }

        // Persisted datetimes and reservation deadlines use UTC. Changing the
        // application timezone mid-request makes Eloquent reinterpret those
        // timestamps as local wall time and can expire valid reservations.
        config()->set('app.time_format', $timeFormat);

        $request->attributes->set('businessPreferences', [
            'name' => $businessName,
            'logoUrl' => $businessLogoUrl,
            'timezone' => $timezone,
            'timeFormat' => $timeFormat,
        ]);

        return $next($request);
    }
}
