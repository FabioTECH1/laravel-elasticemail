<?php

namespace FabioTech\LaravelElasticEmail\Tests;

use FabioTech\LaravelElasticEmail\ElasticEmailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ElasticEmailServiceProvider::class];
    }
}
