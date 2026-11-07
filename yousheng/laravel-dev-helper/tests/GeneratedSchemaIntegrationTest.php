<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Yousheng\LaravelDevHelper\Console\JsonMigrationGenerator;
use Yousheng\LaravelDevHelper\Console\MakeMigrationJson;

class GeneratedSchemaIntegrationTest extends TestCase
{
    /** @var string[] */
    private $generated = [];

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

    protected function tearDown(): void
    {
        foreach ($this->generated as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGeneratedBlogSchemaCanMigrateAndSeed()
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite extension is required for this test.');
        }

        $data = json_decode(file_get_contents(__DIR__.'/fixtures/blog-schema.json'), true);
        $tables = (new MakeMigrationJson())->parseTables($data);

        $files = (new JsonMigrationGenerator(new Filesystem(), base_path()))
            ->generateTables($tables, true, true, true);

        foreach ($files as $file) {
            $this->generated[] = base_path($file);
        }

        $this->registerApplicationAutoloader();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (['roles', 'users', 'categories', 'posts', 'comments', 'tags', 'post_tag'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] was not created.");
        }

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\UserSeeder', '--force' => true])->assertExitCode(0);
        $this->assertSame(10, \App\Models\User::query()->count());
        $this->assertNotNull(\App\Models\User::query()->first()->role);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PostSeeder', '--force' => true])->assertExitCode(0);
        $this->assertSame(10, \App\Models\Post::query()->count());
        $this->assertNotNull(\App\Models\Post::query()->first()->user);
        $this->assertNotNull(\App\Models\Post::query()->first()->category);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\CommentSeeder', '--force' => true])->assertExitCode(0);
        $this->assertSame(10, \App\Models\Comment::query()->count());
        $this->assertNotNull(\App\Models\Comment::query()->first()->post);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\TagSeeder', '--force' => true])->assertExitCode(0);
        $this->assertSame(10, \App\Models\Tag::query()->count());

        $post = \App\Models\Post::query()->first();
        $tag = \App\Models\Tag::query()->first();

        \App\Models\PostTag::factory()->create([
            'post_id' => $post->id,
            'tag_id' => $tag->id,
        ]);

        $this->assertSame(1, $post->tags()->count());
        $this->assertSame(1, $tag->posts()->count());

        $this->assertDatabaseHas('post_tag', [
            'post_id' => $post->id,
            'tag_id' => $tag->id,
        ]);
    }

    /**
     * Testbench 的 laravel 应用没有单独的 composer dump-autoload，
     * 这里补一个 PSR-4 自动加载器，模拟真实 Laravel 应用的 App\ / Database\ 映射。
     */
    private function registerApplicationAutoloader()
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        spl_autoload_register(function ($class) {
            $map = [
                'App\\' => app_path(),
                'Database\\Factories\\' => database_path('factories'),
                'Database\\Seeders\\' => database_path('seeders'),
            ];

            foreach ($map as $prefix => $directory) {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    continue;
                }

                $file = rtrim($directory, '/\\').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        });

        $registered = true;
    }
}
