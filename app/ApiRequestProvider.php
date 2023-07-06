<?php

namespace Carsdotcom\ApiRequest;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class ApiRequestProvider extends ServiceProvider implements DeferrableProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/api-request.php' => config_path('api-request.php'),
        ]);
    }
}