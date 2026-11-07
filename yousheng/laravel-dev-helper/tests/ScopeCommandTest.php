<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

class ScopeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $path = app_path('Scopes/TestingScope.php');
        if (is_file($path)) {
            unlink($path);
        }
    }

    protected function tearDown(): void
    {
        $path = app_path('Scopes/TestingScope.php');
        if (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testItCanExecuteTheMakeScopeCommand()
    {
        $this->artisan('make:scope TestingScope')
            ->assertExitCode(0)
            ->execute();

        $this->assertFileExists(app_path('Scopes/TestingScope.php'));

        $this->assertFileEquals(
            __DIR__.'/../src/Console/stubs/scope_test.stub',
            app_path('Scopes/TestingScope.php')
        );
    }
}
