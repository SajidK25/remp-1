<?php

namespace App\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Madewithlove\IlluminatePsrCacheBridge\Laravel\CacheItemPool;
use Illuminate\Support\ServiceProvider;
use DeviceDetector\Cache\PSR6Bridge;
use DeviceDetector\DeviceDetector;
use Illuminate\Support\Facades\Redis;

class DeviceDetectorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register()
    {
        $this->app->bind(DeviceDetector::class, function ($app) {
            $dd = new DeviceDetector();
            $dd->setCache(
                new PSR6Bridge(
                    new \Cache\Adapter\Predis\PredisCachePool(Redis::connection())
                )
            );

            return $dd;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [DeviceDetector::class];
    }
}
