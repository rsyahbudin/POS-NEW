<?php

namespace App\Providers;

use App\Domain\Ordering\Models\Order;
use App\Domain\Settings\OutletContext;
use App\Policies\OrderPolicy;
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
    public function register(): void
    {
        $this->app->singleton(OutletContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::policy(Order::class, OrderPolicy::class);

        // Public QR pages: limited per IP and per table token (PRD 8).
        RateLimiter::for('qr', fn (Request $r) => [
            Limit::perMinute(40)->by('qr-ip:'.$r->ip()),
            Limit::perMinute(60)->by('qr-table:'.$r->route('token')),
        ]);
        RateLimiter::for('qr-submit', fn (Request $r) => [
            Limit::perMinute(6)->by('qr-submit-ip:'.$r->ip()),
            Limit::perMinute(10)->by('qr-submit-table:'.$r->route('token')),
        ]);
        // The owner may do everything, regardless of individually assigned permissions.
        Gate::before(fn ($user) => $user->hasRole('owner') ? true : null);
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
