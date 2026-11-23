<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\ArraySessionHandler;
use App\Support\UnitContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UnitContext::class, function ($app): UnitContext {
            return new UnitContext($app['request']);
        });
    }

    public function boot(): void
    {
        // Owners hold every permission by definition. Unit scoping is still
        // enforced separately by UnitContext and the policies.
        Gate::before(function (User $user): ?bool {
            return $user->hasRole('owner') ? true : null;
        });

        Session::extend('array', function ($app): ArraySessionHandler {
            return new ArraySessionHandler((int) $app['config']->get('session.lifetime'));
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $key = mb_strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
