<?php

namespace App\Providers;

use App\Domain\DataMigration\JsonPublisher;
use App\Domain\DataMigration\PrivateJsonPublisher;
use App\Support\Queue\QueueRuntimeConfiguration;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JsonPublisher::class, PrivateJsonPublisher::class);
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
