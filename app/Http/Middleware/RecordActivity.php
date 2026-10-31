<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            if ($request->user() !== null) {
                app(ActivityLogService::class)->record('request_failed', 'Page', null, $request->route()?->getName(), ['exception' => class_basename($exception)]);
            }
            throw $exception;
        }
        if ($request->user() !== null && $request->route()?->getName() !== null
            && ! $request->is('settings/profile/photo', 'pos/held-carts*', 'customers/search')
            && ! $request->headers->has('X-Inertia-Partial-Data')) {
            $failed = $response->getStatusCode() >= 400 || ($request->hasSession() && $request->session()->has('errors'));
            $product = $request->route('product');
            app(ActivityLogService::class)->record($failed ? 'request_failed' : ($request->isMethod('GET') ? 'viewed' : 'submitted'), 'Page', null, $request->route()->getName(), ['status' => $response->getStatusCode()], productId: $product instanceof Product ? $product->id : null, productName: $product instanceof Product ? $product->name : null);
        }

        return $response;
    }
}
