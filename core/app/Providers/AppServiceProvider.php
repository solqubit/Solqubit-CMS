<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Policies\ContentPolicy;
use App\Policies\PagePolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->letters()->numbers());

        foreach ([Post::class, Media::class, Category::class, Tag::class, Series::class] as $model) {
            Gate::policy($model, ContentPolicy::class);
        }
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        RateLimiter::for('installer', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
