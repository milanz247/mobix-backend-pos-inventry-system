<?php

namespace App\Providers;

use App\Models\User;
use App\PermissionName;
use App\Services\ActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ActivityLogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            $isRegisteredOwner = $user->ownedBusiness()->where('setup_key', 'initial')->exists();

            return $isRegisteredOwner && PermissionName::tryFrom($ability) !== null ? true : null;
        });

        foreach (['created', 'updated', 'deleted'] as $action) {
            Event::listen('eloquent.'.$action.': *', function (string $event, array $models) use ($action): void {
                if (($models[0] ?? null) instanceof Model) {
                    app(ActivityLogService::class)->model($action, $models[0]);
                }
            });
        }
        Event::listen(Failed::class, fn () => app(ActivityLogService::class)->record('login_failed', 'Session', null, 'Failed sign-in'));
        Event::listen(Login::class, fn ($event) => app(ActivityLogService::class)->record('login', 'Session', null, 'Signed in', actor: $event->user));
        Event::listen(Logout::class, fn ($event) => app(ActivityLogService::class)->record('logout', 'Session', null, 'Signed out', actor: $event->user));
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
