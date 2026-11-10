<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use PHPUnit\Framework\TestCase;
use Yousheng\LaravelDevHelper\Console\JsonFieldCompiler;
use Yousheng\LaravelDevHelper\Console\MakeMigrationJson;

class MakeMigrationJsonTest extends TestCase
{
    /** @var MakeMigrationJson */
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MakeMigrationJson();
    }

    private function blogSchema()
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/blog-schema.json'), true);
    }

    public function testItParsesTableDirectivesIntoMigrationSpecs()
    {
        $tables = $this->parser->parseTables($this->blogSchema());

        $this->assertSame(
            ['create_roles_table', 'create_users_table', 'create_categories_table', 'create_posts_table', 'create_comments_table', 'create_tags_table', 'create_post_tag_table'],
            array_keys($tables)
        );

        $users = $tables['create_users_table'];
        $this->assertSame('users', $users['table']);
        $this->assertSame('用户表', $users['comment']);
        $this->assertSame('index', $users['indexes'][0]['type']);
        $this->assertSame(['name'], $users['indexes'][0]['columns']);
        $this->assertSame('roles', $users['foreignKeys'][0]['on']);
        $this->assertSame(['id'], $users['foreignKeys'][0]['references']);
        $this->assertSame(['role_id'], $users['foreignKeys'][0]['columns']);
        $this->assertSame('cascade', $users['foreignKeys'][0]['onDelete']);
        $this->assertSame('set null', $users['foreignKeys'][0]['onUpdate']);

        $posts = $tables['create_posts_table'];
        $this->assertSame('文章表', $posts['comment']);
        $this->assertTrue($posts['softDeletes']);
        $this->assertSame('unique', $posts['indexes'][1]['type']);
        $this->assertSame(['slug'], $posts['indexes'][1]['columns']);
        $this->assertSame('users', $posts['foreignKeys'][0]['on']);
        $this->assertSame('cascade', $posts['foreignKeys'][0]['onDelete']);
        $this->assertSame('categories', $posts['foreignKeys'][1]['on']);
        $this->assertSame('set null', $posts['foreignKeys'][1]['onDelete']);

        $this->assertFalse($tables['create_post_tag_table']['timestamps']);
        $this->assertSame(['post_id', 'tag_id'], $tables['create_post_tag_table']['primary']);
    }

    public function testItSortsReferencedTablesFirst()
    {
        $tables = $this->parser->parseTables($this->blogSchema());
        $order = array_keys($tables);

        $this->assertLessThan(array_search('create_users_table', $order), array_search('create_roles_table', $order));
        $this->assertLessThan(array_search('create_posts_table', $order), array_search('create_users_table', $order));
        $this->assertLessThan(array_search('create_posts_table', $order), array_search('create_categories_table', $order));
        $this->assertLessThan(array_search('create_comments_table', $order), array_search('create_posts_table', $order));
        $this->assertLessThan(array_search('create_post_tag_table', $order), array_search('create_posts_table', $order));
        $this->assertLessThan(array_search('create_post_tag_table', $order), array_search('create_tags_table', $order));
    }

    public function testItParsesCompositeIndexesAndConstraints()
    {
        $tables = $this->parser->parseTables([
            'items' => [
                'table' => 'index(a,b|idx_ab):unique(c):primary(a,b):constraint(refs|x,y|a,b|cascade|set null|fk_ab):engine(InnoDB):charset(utf8mb4):collation(utf8mb4_unicode_ci):timestamps(false):softDeletes',
                'a' => 'unsignedBigInteger',
                'b' => 'unsignedBigInteger',
                'c' => 'string',
            ],
        ]);

        $items = $tables['create_items_table'];
        $this->assertSame(['a', 'b'], $items['indexes'][0]['columns']);
        $this->assertSame('idx_ab', $items['indexes'][0]['name']);
        $this->assertSame('unique', $items['indexes'][1]['type']);
        $this->assertSame(['a', 'b'], $items['primary']);
        $this->assertSame(['a', 'b'], $items['foreignKeys'][0]['columns']);
        $this->assertSame(['x', 'y'], $items['foreignKeys'][0]['references']);
        $this->assertSame('fk_ab', $items['foreignKeys'][0]['name']);
        $this->assertSame('InnoDB', $items['engine']);
        $this->assertSame('utf8mb4', $items['charset']);
        $this->assertSame('utf8mb4_unicode_ci', $items['collation']);
        $this->assertFalse($items['timestamps']);
        $this->assertTrue($items['softDeletes']);
    }

    public function testItAllowsColonsInsideComment()
    {
        $tables = $this->parser->parseTables([
            'users' => [
                'table' => 'comment(说明: 用户表)',
                'id' => 'id',
            ],
        ]);

        $this->assertSame('说明: 用户表', $tables['create_users_table']['comment']);
    }

    public function testTableDirectiveMustBeTheFirstKey()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be the first key');

        $this->parser->parseTables([
            'users' => [
                'email' => 'string',
                'table' => 'comment(用户表)',
            ],
        ]);
    }

    public function testCircularDependenciesAreRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular foreign key dependency');

        $this->parser->parseTables([
            'a' => [
                'table' => 'constraint(b|id|b_id)',
                'id' => 'id',
                'b_id' => 'unsignedBigInteger',
            ],
            'b' => [
                'table' => 'constraint(a|id|a_id)',
                'id' => 'id',
                'a_id' => 'unsignedBigInteger',
            ],
        ]);
    }

    public function testValidationReportsUnknownDirectivesAndMissingColumns()
    {
        $errors = $this->parser->validateSchema([
            'users' => [
                'table' => 'unknown(user)',
                'id' => 'id',
            ],
            'posts' => [
                'table' => 'index(missing)',
                'id' => 'id',
            ],
        ]);

        $this->assertArrayHasKey('__table__', $errors['users']);
        $this->assertArrayHasKey('__indexes__', $errors['posts']);
    }

    public function testExampleSchemaIsValid()
    {
        $data = json_decode(file_get_contents(__DIR__.'/../schema.example.json'), true);

        $this->assertSame([], $this->parser->validateSchema($data));
        $this->assertCount(10, $this->parser->parseTables($data));
    }

    public function testExampleJson5SchemaIsValid()
    {
        $data = $this->parser->jsonFileToArray(__DIR__.'/../schema.example.json5');

        $this->assertIsArray($data);
        $this->assertSame([], $this->parser->validateSchema($data));
        $this->assertCount(7, $this->parser->parseTables($data));
    }

    public function testFullReferenceSchemaIsValid()
    {
        $data = $this->parser->jsonFileToArray(__DIR__.'/fixtures/full-schema.json5');

        $this->assertIsArray($data);
        $this->assertSame([], $this->parser->validateSchema($data));
        $this->assertCount(20, $this->parser->parseTables($data));
    }

    public function testItParsesJson5FilesWithCommentsAndTrailingCommas()
    {
        $data = $this->parser->jsonFileToArray(__DIR__.'/fixtures/blog-schema.json5');

        $this->assertIsArray($data);
        $this->assertSame(
            "enum('0','1'):default('0'):comment('0 means unpublished, 1 means published')",
            $data['posts']['status']
        );

        $tables = $this->parser->parseTables($data);
        $this->assertEqualsCanonicalizing(
            [
                'create_posts_table',
                'create_users_table',
                'create_tags_table',
                'create_post_tag_table',
                'create_comments_table',
            ],
            array_keys($tables)
        );
        $this->assertSame('文章表', $tables['create_posts_table']['comment']);
    }

    public function testItParsesPlainJsonWithCommentsAsJson5Fallback()
    {
        $path = tempnam(sys_get_temp_dir(), 'schema').'.json';
        file_put_contents($path, <<<'JSON'
{
    // 用户表
    users: {
        table: 'comment(用户表)',
        id: 'id',
        name: 'string(50)',
    },
}
JSON);

        $data = $this->parser->jsonFileToArray($path);
        @unlink($path);

        $this->assertIsArray($data);
        $this->assertSame('comment(用户表)', $data['users']['table']);
    }

    public function testInvalidJson5ReturnsNull()
    {
        $path = tempnam(sys_get_temp_dir(), 'schema').'.json5';
        file_put_contents($path, '{ users: { id: ');

        $this->assertNull($this->parser->jsonFileToArray($path));
        @unlink($path);
    }

    public function testFullReferenceCoversEverySupportedTypeAndModifier()
    {
        $data = $this->parser->jsonFileToArray(__DIR__.'/fixtures/full-schema.json5');
        $tables = $this->parser->parseTables($data);
        $compiler = new JsonFieldCompiler();

        $types = [];
        $modifiers = [];
        foreach ($tables as $spec) {
            $definitions = [];
            foreach ($spec['columns'] as $name => $definition) {
                $definitions[] = "{$name}:{$definition}";
            }

            foreach ($compiler->parse(implode(', ', $definitions)) as $field) {
                $types[$field['type']] = true;
                foreach ($field['modifiers'] as $modifier) {
                    $modifiers[$modifier['name']] = true;
                }
            }
        }

        $allTypes = (new \ReflectionClassConstant(MakeMigrationJson::class, 'COLUMN_TYPES'))->getValue();
        $allModifiers = (new \ReflectionClassConstant(MakeMigrationJson::class, 'COLUMN_MODIFIERS'))->getValue();

        $this->assertSame([], array_values(array_diff($allTypes, array_keys($types))));
        $this->assertSame([], array_values(array_diff($allModifiers, array_keys($modifiers))));
    }
}
