<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DatabaseCommandsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite extension is required for this test.');
        }

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    public function testTheModelCommandGeneratesAModelFromAnExistingTable()
    {
        $this->artisan('yousheng:model', [
            'name' => 'unused',
            '--only' => 'widgets',
            '--overwrite' => true,
        ])->assertExitCode(0);

        $path = app_path('Models/Widget.php');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString("protected \$table = 'widgets';", $contents);
        $this->assertStringContainsString("'name'", $contents);
    }

    public function testTheOneCommandGeneratesAModel()
    {
        $this->artisan('yousheng:one', [
            'name' => 'unused',
            '--only' => 'widgets',
            '--overwrite' => true,
        ])->assertExitCode(0);

        $path = app_path('Models/Widget.php');
        $this->assertFileExists($path);
        $this->assertStringContainsString("protected \$table = 'widgets';", file_get_contents($path));
    }

    public function testTheNovaCommandGeneratesAResource()
    {
        $this->artisan('yousheng:nova', [
            '--only' => 'widgets',
            '--overwrite' => true,
        ])->assertExitCode(0);

        $path = app_path('Nova/Widget.php');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('class Widget extends Resource', $contents);
        $this->assertStringContainsString("Text::make('name')", $contents);
    }

    private function cleanup()
    {
        foreach ([
            app_path('Models/Widget.php'),
            app_path('Widget.php'),
            app_path('Nova/Widget.php'),
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
