<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Yousheng\LaravelDevHelper\Http\Middleware\First;
use Yousheng\LaravelDevHelper\Http\Middleware\Last;

class CommandRegistrationTest extends TestCase
{
    public function testThePackageCommandsAreRegistered()
    {
        $commands = $this->app->make(Kernel::class)->all();

        foreach ([
            'db:migrate',
            'yousheng:model',
            'yousheng:nova',
            'yousheng:one',
            'make:scope',
            'make:migration:json',
        ] as $command) {
            $this->assertArrayHasKey($command, $commands, "Command [{$command}] is not registered.");
        }
    }

    public function testTheJsonMigrationCommandCanValidateASchema()
    {
        $this->artisan('make:migration:json', [
            '--file' => __DIR__.'/fixtures/blog-schema.json',
            '--validate' => true,
        ])->assertExitCode(0);
    }

    public function testTheJsonMigrationCommandSupportsModelFactorySeederShortcuts()
    {
        $commands = $this->app->make(Kernel::class)->all();
        $definition = $commands['make:migration:json']->getDefinition();

        $this->assertSame('m', $definition->getOption('model')->getShortcut());
        $this->assertSame('f', $definition->getOption('factory')->getShortcut());
        $this->assertSame('s', $definition->getOption('seeder')->getShortcut());

        $input = new \Symfony\Component\Console\Input\StringInput('-mfs');
        $input->bind($definition);

        $this->assertTrue($input->getOption('model'));
        $this->assertTrue($input->getOption('factory'));
        $this->assertTrue($input->getOption('seeder'));
    }

    public function testTheGlobalDebugMiddlewareIsRegistered()
    {
        $kernel = $this->app->make(HttpKernel::class);

        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);
        $middleware = $property->getValue($kernel);

        $this->assertContains(First::class, $middleware);
        $this->assertContains(Last::class, $middleware);
    }
}
