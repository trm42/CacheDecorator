<?php

namespace Trm42\CacheDecorator;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/cache_decorator.php' => config_path('cache_decorator.php'),
        ], 'cache-decorator-config');

        $this->publishes([
            __DIR__.'/../config/repository_cache.php' => config_path('repository_cache.php'),
        ], 'repository-cache-config');
    }

    public function register(): void {}
}
