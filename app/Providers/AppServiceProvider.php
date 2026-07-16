<?php

namespace App\Providers;

use App\Support\Queue\QueueRuntimeConfiguration;
use Illuminate\Support\ServiceProvider;

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
        QueueRuntimeConfiguration::assertSafe(
            config('queue.default'),
            config('queue.connections'),
        );
    }
}
