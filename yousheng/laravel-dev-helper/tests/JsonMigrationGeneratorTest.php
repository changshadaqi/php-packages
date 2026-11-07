<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Yousheng\LaravelDevHelper\Console\JsonMigrationGenerator;
use Yousheng\LaravelDevHelper\Console\MakeMigrationJson;

class JsonMigrationGeneratorTest extends TestCase
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->basePath = sys_get_temp_dir().'/dev-helper-simple-'.uniqid();
        $this->filesystem->makeDirectory($this->basePath.'/app/Models', 0755, true, true);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->isDirectory($this->basePath)) {
            $this->filesystem->deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    private function blogTables()
    {
        $data = json_decode(file_get_contents(__DIR__.'/fixtures/blog-schema.json'), true);

        return (new MakeMigrationJson())->parseTables($data);
    }

    private function generate($models = false, $factories = false, $seeders = false)
    {
        return (new JsonMigrationGenerator($this->filesystem, $this->basePath))
            ->generateTables($this->blogTables(), $models, $factories, $seeders);
    }

    public function testItGeneratesMigrationsInDependencyOrderWithTableOptions()
    {
        $files = $this->generate();

        $this->assertCount(7, $files);

        $roles = $this->indexOf($files, '_create_roles_table.php');
        $users = $this->indexOf($files, '_create_users_table.php');
        $categories = $this->indexOf($files, '_create_categories_table.php');
        $posts = $this->indexOf($files, '_create_posts_table.php');
        $comments = $this->indexOf($files, '_create_comments_table.php');
        $tags = $this->indexOf($files, '_create_tags_table.php');
        $postTag = $this->indexOf($files, '_create_post_tag_table.php');

        $this->assertLessThan($users, $roles);
        $this->assertLessThan($posts, $users);
        $this->assertLessThan($posts, $categories);
        $this->assertLessThan($comments, $posts);
        $this->assertLessThan($postTag, $tags);

        $postsMigration = $this->contentsOf($files, '_create_posts_table.php');
        $this->assertStringContainsString("Schema::create('posts'", $postsMigration);
        $this->assertStringContainsString("\$table->comment('文章表');", $postsMigration);
        $this->assertStringContainsString("\$table->softDeletes();", $postsMigration);
        $this->assertStringContainsString("\$table->index(['title']);", $postsMigration);
        $this->assertStringContainsString("\$table->unique(['slug']);", $postsMigration);
        $this->assertStringContainsString(
            "\$table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');",
            $postsMigration
        );
        $this->assertStringContainsString(
            "\$table->foreign(['category_id'])->references(['id'])->on('categories')->onUpdate('restrict')->onDelete('set null');",
            $postsMigration
        );

        $postTagMigration = $this->contentsOf($files, '_create_post_tag_table.php');
        $this->assertStringContainsString("\$table->primary(['post_id', 'tag_id']);", $postTagMigration);
        $this->assertStringNotContainsString('$table->timestamps();', $postTagMigration);
    }

    public function testItGeneratesModelsWithRelationsAndCasts()
    {
        $files = $this->generate(true);

        $post = $this->contentsOf($files, 'app/Models/Post.php');
        $this->assertStringContainsString('use SoftDeletes;', $post);
        $this->assertStringContainsString("protected \$fillable = ['user_id', 'category_id', 'title', 'slug', 'body', 'status', 'published_at', 'views'];", $post);
        $this->assertStringContainsString("'views' => 'integer'", $post);
        $this->assertStringContainsString("'published_at' => 'datetime'", $post);
        $this->assertStringContainsString('public function user()', $post);
        $this->assertStringContainsString('public function category()', $post);
        $this->assertStringContainsString('public function comments()', $post);
        $this->assertStringContainsString('public function tags()', $post);
        $this->assertStringContainsString(
            "return \$this->belongsToMany(\\App\\Models\\Tag::class, 'post_tag', 'post_id', 'tag_id');",
            $post
        );

        $user = $this->contentsOf($files, 'app/Models/User.php');
        $this->assertStringContainsString('public function role()', $user);
        $this->assertStringContainsString('public function posts()', $user);
        $this->assertStringContainsString('public function comments()', $user);

        $role = $this->contentsOf($files, 'app/Models/Role.php');
        $this->assertStringContainsString('public function users()', $role);

        $postTag = $this->contentsOf($files, 'app/Models/PostTag.php');
        $this->assertStringContainsString('public $timestamps = false;', $postTag);
        $this->assertStringContainsString('public function post()', $postTag);
        $this->assertStringContainsString('public function tag()', $postTag);
    }

    public function testItGeneratesFactoriesAndSeeders()
    {
        $files = $this->generate(true, true, true);

        $postFactory = $this->contentsOf($files, 'database/factories/PostFactory.php');
        $this->assertStringContainsString('protected $model = \\App\\Models\\Post::class;', $postFactory);
        $this->assertStringContainsString('\\App\\Models\\User::factory()', $postFactory);
        $this->assertStringContainsString('\\App\\Models\\Category::factory()', $postFactory);
        $this->assertStringContainsString('fake()->sentence(3)', $postFactory);
        $this->assertStringContainsString("fake()->randomElement(['draft', 'published'])", $postFactory);

        $userFactory = $this->contentsOf($files, 'database/factories/UserFactory.php');
        $this->assertStringContainsString('\\App\\Models\\Role::factory()', $userFactory);
        $this->assertStringContainsString('fake()->unique()->safeEmail()', $userFactory);
        $this->assertStringContainsString("Hash::make('password')", $userFactory);

        $postSeeder = $this->contentsOf($files, 'database/seeders/PostSeeder.php');
        $this->assertStringContainsString('\\App\\Models\\Post::factory()->count(10)->create();', $postSeeder);
    }

    public function testFlagsControlGeneratedFileTypes()
    {
        $migrationOnly = (new JsonMigrationGenerator($this->filesystem, $this->basePath.'/migrations-only'))
            ->generateTables($this->blogTables(), false, false, false);
        $this->assertCount(7, $migrationOnly);

        $withModels = (new JsonMigrationGenerator($this->filesystem, $this->basePath.'/models'))
            ->generateTables($this->blogTables(), true, false, false);
        $this->assertCount(14, $withModels);

        $withFactories = (new JsonMigrationGenerator($this->filesystem, $this->basePath.'/factories'))
            ->generateTables($this->blogTables(), true, true, false);
        $this->assertCount(21, $withFactories);

        $all = (new JsonMigrationGenerator($this->filesystem, $this->basePath.'/all'))
            ->generateTables($this->blogTables(), true, true, true);
        $this->assertCount(28, $all);
    }

    public function testItGeneratesCompositeTableOptions()
    {
        $tables = (new MakeMigrationJson())->parseTables([
            'items' => [
                'table' => 'comment(物品表):primary(a,b):index(a,b|idx_ab):engine(InnoDB):charset(utf8mb4):collation(utf8mb4_unicode_ci)',
                'a' => 'unsignedBigInteger',
                'b' => 'unsignedBigInteger',
            ],
        ]);

        $files = (new JsonMigrationGenerator($this->filesystem, $this->basePath))
            ->generateTables($tables);

        $migration = $this->contentsOf($files, '_create_items_table.php');
        $this->assertStringContainsString("\$table->primary(['a', 'b']);", $migration);
        $this->assertStringContainsString("\$table->index(['a', 'b'], 'idx_ab');", $migration);
        $this->assertStringContainsString("\$table->engine = 'InnoDB';", $migration);
        $this->assertStringContainsString("\$table->charset = 'utf8mb4';", $migration);
        $this->assertStringContainsString("\$table->collation = 'utf8mb4_unicode_ci';", $migration);
    }

    public function testFullReferenceSchemaCanGenerateAllFileTypes()
    {
        $data = (new MakeMigrationJson())->jsonFileToArray(__DIR__.'/fixtures/full-schema.json5');
        $this->assertIsArray($data);

        $tables = (new MakeMigrationJson())->parseTables($data);
        $files = (new JsonMigrationGenerator($this->filesystem, $this->basePath))
            ->generateTables($tables, true, true, true);

        // 20 张表 × (迁移 + 模型 + 工厂 + 种子)
        $this->assertCount(80, $files);
    }

    private function indexOf(array $files, $suffix)
    {
        foreach ($files as $index => $file) {
            if (str_ends_with($file, $suffix)) {
                return $index;
            }
        }

        $this->fail("Generated file [{$suffix}] was not found.");
    }

    private function contentsOf(array $files, $suffix)
    {
        $index = $this->indexOf($files, $suffix);

        return file_get_contents($this->basePath.'/'.$files[$index]);
    }
}
