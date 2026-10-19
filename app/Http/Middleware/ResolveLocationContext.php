<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\LocationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveLocationContext
{
    public function __construct(private readonly LocationAccessService $locations) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! Business::query()->where('setup_key', 'initial')->exists()) {
            return $next($request);
        }

        if ($this->locations->selectedLocationId($request) === null) {
            $request->session()->forget('active_location_id');
        }

        if ($request->isMethod('GET') && $request->filled('location_id')) {
            abort_unless(filter_var($request->query('location_id'), FILTER_VALIDATE_INT) !== false, 404);
            $location = $this->locations->accessibleQuery($user)->findOrFail($request->integer('location_id'));
            if ($location->status->value === 'active') {
                $request->session()->put('active_location_id', $location->id);
            }
        }

        if (! $request->session()->has('active_location_id')) {
            $locations = $this->locations->accessibleQuery($user)->where('status', 'active')->limit(2)->pluck('id');
            if ($locations->count() === 1) {
                $request->session()->put('active_location_id', $locations->first());
            }
        }

        return $next($request);
    }
}
