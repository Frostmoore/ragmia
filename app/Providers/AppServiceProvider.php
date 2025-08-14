<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Message;
use App\Observers\MessageObserver;

class AppServiceProvider extends ServiceProvider
{
    public const HOME = '/';
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
        Message::observe(MessageObserver::class);
    }
}
