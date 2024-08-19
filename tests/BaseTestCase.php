<?php

namespace Tests;

use Carsdotcom\ApiRequest\AbstractRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;

class BaseTestCase extends TestCase
{
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('filesystems.disks.local.root', realpath(__DIR__.'/data'));
        // Setup default database to use sqlite :memory:
        $app['config']->set('api-request.logs_storage_disk_name', 'api-logs');
        $app['config']->set('api-request.cache_key_seed', 'v1.00');
        $app['config']->set('api-request.tapper_data_storage_disk_name', 'local');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
