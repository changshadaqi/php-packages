<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

class JsonMigrationCommandTest extends TestCase
{
    /** @var string */
    private $jsonPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jsonPath = sys_get_temp_dir().'/dev-helper-blog-'.uniqid().'.json';
        $this->cleanupGeneratedFiles();
    }

    protected function tearDown(): void
    {
        @unlink($this->jsonPath);
        @unlink($this->jsonPath.'.undo.json');
        $this->cleanupGeneratedFiles();

        parent::tearDown();
    }

    private function useBlogSchema()
    {
        copy(__DIR__.'/fixtures/blog-schema.json', $this->jsonPath);
    }

    public function testItGeneratesMigrationsModelsFactoriesAndSeedersWithShortFlags()
    {
        $this->useBlogSchema();

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--model' => true,
            '--factory' => true,
            '--seeder' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(app_path('Models/Post.php'));
        $this->assertFileExists(app_path('Models/User.php'));
        $this->assertFileExists(app_path('Models/PostTag.php'));
        $this->assertFileExists(database_path('factories/PostFactory.php'));
        $this->assertFileExists(database_path('seeders/PostSeeder.php'));

        $migrations = glob(base_path('database/migrations/*_create_*_table.php')) ?: [];
        $this->assertNotEmpty($migrations);

        $ordered = array_values(array_filter(array_map('basename', $migrations), function ($file) {
            return in_array($file, [
                'create_roles_table.php',
                'create_users_table.php',
                'create_categories_table.php',
                'create_posts_table.php',
                'create_comments_table.php',
                'create_tags_table.php',
                'create_post_tag_table.php',
            ]) || preg_match('/_create_(roles|users|categories|posts|comments|tags|post_tag)_table\.php$/', $file);
        }));

        sort($ordered);

        $positions = [];
        foreach ($ordered as $index => $file) {
            foreach (['roles', 'users', 'categories', 'posts', 'comments', 'tags', 'post_tag'] as $table) {
                if (str_ends_with($file, "_create_{$table}_table.php")) {
                    $positions[$table] = $index;
                }
            }
        }

        $this->assertLessThan($positions['users'], $positions['roles']);
        $this->assertLessThan($positions['posts'], $positions['users']);
        $this->assertLessThan($positions['posts'], $positions['categories']);
        $this->assertLessThan($positions['post_tag'], $positions['tags']);

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--undo' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist(app_path('Models/Post.php'));
        $this->assertFileDoesNotExist(database_path('factories/PostFactory.php'));
        $this->assertFileDoesNotExist(database_path('seeders/PostSeeder.php'));
    }

    public function testSeederFlagImpliesFactoryAndModel()
    {
        $this->useBlogSchema();

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--seeder' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(app_path('Models/Post.php'));
        $this->assertFileExists(database_path('factories/PostFactory.php'));
        $this->assertFileExists(database_path('seeders/PostSeeder.php'));
    }

    public function testItValidatesTheBlogSchema()
    {
        $this->useBlogSchema();

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--validate' => true,
        ])->assertExitCode(0);
    }

    public function testItValidatesJson5SchemaWithComments()
    {
        copy(__DIR__.'/fixtures/blog-schema.json5', $this->jsonPath);

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--validate' => true,
        ])->assertExitCode(0);
    }

    public function testItReportsInvalidSchema()
    {
        file_put_contents($this->jsonPath, json_encode([
            'users' => [
                'email' => 'string',
                'table' => 'comment(用户表)',
            ],
        ]));

        $this->artisan('make:migration:json', [
            '--file' => $this->jsonPath,
            '--validate' => true,
        ])->assertExitCode(1);
    }

    private function cleanupGeneratedFiles()
    {
        $tables = ['roles', 'users', 'categories', 'posts', 'comments', 'tags', 'post_tag'];
        $models = ['Role', 'User', 'Category', 'Post', 'Comment', 'Tag', 'PostTag'];

        foreach ($tables as $table) {
            foreach (glob(base_path("database/migrations/*_create_{$table}_table.php")) ?: [] as $file) {
                @unlink($file);
            }
        }

        foreach ($models as $model) {
            @unlink(app_path("Models/{$model}.php"));
            @unlink(database_path("factories/{$model}Factory.php"));
            @unlink(database_path("seeders/{$model}Seeder.php"));
        }
    }
}
