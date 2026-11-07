<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use Yousheng\LaravelDevHelper\HelperServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [HelperServiceProvider::class];
    }
}
