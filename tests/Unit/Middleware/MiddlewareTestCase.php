<?php

namespace Tests\Unit\Middleware;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

abstract class MiddlewareTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $container->instance('config', new Repository());
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}
