<?php

use App\Exceptions\StorefrontCartException;
use App\Http\Middleware\ApplyBusinessPreferences;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveLocationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            ApplyBusinessPreferences::class,
            ResolveLocationContext::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (StorefrontCartException $exception, Request $request) {
            if (! $request->is('api/storefront/v1/*')) {
                return null;
            }

            return response()->json(['error' => [
                'code' => $exception->errorCode, 'message' => $exception->getMessage(), 'fields' => new stdClass,
            ], 'request_id' => Str::uuid()->toString()], $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/storefront/v1/*')) {
                return null;
            }

            return response()->json(['error' => [
                'code' => $request->is('api/storefront/v1/cart*', 'api/storefront/v1/quotes', 'api/storefront/v1/order-offers', 'api/storefront/v1/orders', 'api/storefront/v1/orders/*/bank-transfer-proof') ? 'INVALID_REQUEST' : 'INVALID_FILTER',
                'message' => 'The request parameters are invalid.', 'fields' => $exception->errors(),
            ], 'request_id' => Str::uuid()->toString()], 422)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/storefront/v1/*')) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'fields' => new stdClass,
            ], 'request_id' => Str::uuid()->toString()], 404)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/storefront/v1/*')) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'RATE_LIMITED', 'message' => 'Please try again later.', 'fields' => new stdClass,
            ], 'request_id' => Str::uuid()->toString()], 429)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
