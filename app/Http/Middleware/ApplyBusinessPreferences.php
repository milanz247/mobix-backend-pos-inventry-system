<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
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
        $business = Business::query()->first(['name', 'timezone', 'time_format']);
        $businessName = null;
        $timezone = config()->string('app.timezone');
        $timeFormat = config()->string('app.time_format');

        if ($business !== null) {
            $businessName = $business->name;
            $timezone = $business->timezone;
            $timeFormat = $business->time_format->value;
        }

        config()->set([
            'app.timezone' => $timezone,
            'app.time_format' => $timeFormat,
        ]);

        date_default_timezone_set($timezone);

        $request->attributes->set('businessPreferences', [
            'name' => $businessName,
            'timezone' => $timezone,
            'timeFormat' => $timeFormat,
        ]);

        return $next($request);
    }
}
