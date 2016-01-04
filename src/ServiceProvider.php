<?php

namespace Trm42\CacheDecorator;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider {
	
	/**
	 * Perform post-registration booting of services.
	 *
	 * @return void
	 */
	public function boot()
	{
	    $this->publishes([
	        __DIR__.'/../config/repository_cache.php' => config_path('repository_cache.php'),
	    ]);
	}

	public function register() 
	{
	
	}

}