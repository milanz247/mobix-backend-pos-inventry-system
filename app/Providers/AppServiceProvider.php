<?php

namespace App\Providers;

use App\Models\User;
use App\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('storefront-catalog', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('storefront-cart', fn (Request $request): array => [
            Limit::perMinute(3000)->by('ip:'.$request->ip()),
            Limit::perMinute($request->header('X-Storefront-Cart-Token') ? 120 : 300)->by('cart:'.($request->header('X-Storefront-Cart-Token')
                ? hash('sha256', (string) $request->header('X-Storefront-Cart-Token'))
                : (string) $request->ip())),
        ]);
        RateLimiter::for('storefront-offers', fn (Request $request): array => [
            Limit::perMinute(30)->by('offer-ip:'.$request->ip()),
            Limit::perMinute(5)->by('offer-cart:'.($request->header('X-Storefront-Cart-Token')
                ? hash('sha256', (string) $request->header('X-Storefront-Cart-Token'))
                : (string) $request->ip())),
        ]);
        RateLimiter::for('storefront-orders', fn (Request $request): array => [
            Limit::perMinute(20)->by('order-ip:'.$request->ip()),
            Limit::perMinute(5)->by('order-cart:'.($request->header('X-Storefront-Cart-Token')
                ? hash('sha256', (string) $request->header('X-Storefront-Cart-Token'))
                : (string) $request->ip())),
        ]);
        RateLimiter::for('storefront-order-tracking', fn (Request $request): array => [
            Limit::perMinute(3000)->by('tracking-proxy-ip:'.$request->ip()),
            Limit::perMinute(20)->by('tracking-reference:'.(string) $request->route('reference')),
            Limit::perMinute(20)->by('tracking-credential:'.($request->header('X-Storefront-Order-Credential')
                ? hash('sha256', (string) $request->header('X-Storefront-Order-Credential'))
                : (string) $request->ip())),
        ]);
        RateLimiter::for('storefront-bank-proof', fn (Request $request): array => [
            Limit::perMinute(30)->by('bank-proof-proxy-ip:'.$request->ip()),
            Limit::perMinute(3)->by('bank-proof-order:'.(string) $request->route('reference')),
            Limit::perMinute(3)->by('bank-proof-credential:'.hash('sha256', (string) $request->header('X-Storefront-Order-Credential'))),
        ]);
        Gate::before(function (User $user, string $ability): ?bool {
            $isRegisteredOwner = $user->ownedBusiness()->where('setup_key', 'initial')->exists();

            return $isRegisteredOwner && PermissionName::tryFrom($ability) !== null ? true : null;
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
