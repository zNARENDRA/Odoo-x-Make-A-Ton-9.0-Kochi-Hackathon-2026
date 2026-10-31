<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::useOrigin(config('app.url'));
            URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        }

        Password::defaults(fn () => Password::min(9)->mixedCase()->symbols());
        Gate::define('manage-catalog', fn (User $user): bool => $user->role === 'admin');
        Gate::define('manage-billing', fn (User $user): bool => $user->isTeamMember());
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
